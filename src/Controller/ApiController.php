<?php

namespace App\Controller;

use App\Entity\Commit;
use App\Entity\Package;
use App\Entity\Queue;
use App\Entity\PackageDependency;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ApiController extends Controller
{
    /**
     * @Route("/api/pull-hook/gitlab", name="gitlab_pull")
     */
    public function gitlab_pull(Request $request)
    {
        $token = $request->headers->get('X-Gitlab-Token');
        if (!$token) {
            $this->get('web_log')->write('gitlab-pull received without secret', null, true);
            return new JsonResponse(['error' => 'Gitlab secret missing'], 401);
        }


        if (!hash_equals(sha1($this->getParameter('kernel.secret')), $token)) {
            $this->get('web_log')->write('gitlab-pull received with incorrect secret', null, true);
            return new JsonResponse(['error' => 'Secret not correct'], 403);
        }

        $payload = $request->getContent();
        $payload = json_decode($payload, true);

        $this->get('web_log')->write('gitlab-pull received', $payload, true);

        $event = $request->headers->get('X-Gitlab-Event');

        switch ($event) {
            case 'Push Hook':
                $branch = str_replace('refs/heads/', '', $payload['ref']);

                $message = '';
                if (isset($payload['commits'][0]['message'])) {
                    $message = $payload['commits'][0]['message'];
                }

                $manifest = $this->onNewPush($branch, $payload['checkout_sha'], $message);
                return new Response($manifest);
                break;
        }
        return new JsonResponse(['error' => 'Unknown event "' . $event . '"'], 400);
    }

    /**
     * Add list of packages to the build queue as tasks
     *
     * @Route("/api/task-submit", name="task_submit")
     */
    public function task_submit(Request $request)
    {
        $token = $request->headers->get('X-Secret');
        $commit_str = $request->headers->get('X-Commit');
        $architecture = $request->headers->get('X-Arch');

        if (!$token) {
            $this->get('web_log')->write('task-submit received without secret', null, true);
            return new JsonResponse(['error' => 'Secret missing'], 401);
        }

        if (!hash_equals(sha1($this->getParameter('kernel.secret')), $token)) {
            $this->get('web_log')->write('task-submit received with incorrect secret', null, true);
            return new JsonResponse(['error' => 'Secret not correct'], 403);
        }

        $manager = $this->getDoctrine()->getManager();

        $commit = $this->getDoctrine()->getRepository('App:Commit')->findOneBy(['ref' => $commit_str]);

        if (!$commit) {
            throw new \Exception('Commit "' . $commit_str . '" not found in the database');
        }

        if ($commit->getStatus() == 'INDEXING') {
            $commit->setStatus('BUILDING');
        }

        $payload = $request->getContent();
        $payload = json_decode($payload, true);

        // Handle commits that don't change any package
        if (count($payload) == 0) {
            $commit->setStatus('DONE');
            $manager->flush();
            $this->startNextBuild();
            return new JsonResponse(['status' => 'nothing to be done']);
        }

        $this->get('web_log')->write('task-submit received', $payload);

        $tasks = [];
        $result = [];

        // Create all Package and Queue items
        foreach ($payload as $package) {
            list($pkgver, $pkgrel) = explode('-', $package['version'], 2);
            $pkgrel = (int)str_replace('r', '', $pkgrel);
            $component = 'main';
            $tasks[$package['pkgname']] = $this->createOrUpdatePackage($package['pkgname'], $pkgver, $pkgrel, $commit, $architecture, $component);
        }

        // Create PackageDependency rows for the packages if needed
        foreach ($payload as $package) {
            $task = $tasks[$package['pkgname']];
            $result[] = $package['pkgname'];
            foreach ($package['depends'] as $dependency) {
                if (isset($tasks[$dependency])) {
                    $dependendPackage = $tasks[$dependency];
                    $existing = $this->getDoctrine()->getRepository('App:PackageDependency')->findOneBy(['package' => $task, 'requirement' => $dependendPackage]);
                    if (!$existing) {
                        $queueDependency = new PackageDependency();
                        $queueDependency->setPackage($task->getPackage());
                        $queueDependency->setRequirement($dependendPackage->getPackage());
                        $manager->persist($queueDependency);
                    }
                }
            }
        }

        $manager->flush();

        $this->startNextBuild();

        return new JsonResponse($result);
    }

    /**
     * @Route("/api/package-submit", name="package_submit")
     */
    public function package_submit(Request $request)
    {
        $token = $request->headers->get('X-Secret');
        $commit = $request->headers->get('X-Commit');
        $architecture = $request->headers->get('X-Arch');
        $id = $request->headers->get('X-Id');

        if (!$token) {
            $this->get('web_log')->write('task-submit received without secret', null, true);
            return new JsonResponse(['error' => 'Secret missing'], 401);
        }

        if (!hash_equals(sha1($this->getParameter('kernel.secret')), $token)) {
            $this->get('web_log')->write('task-submit received with incorrect secret', null, true);
            return new JsonResponse(['error' => 'Secret not correct'], 403);
        }

        $manager = $this->getDoctrine()->getManager();

        list($pkgname, $pkgver, $pkgrel) = explode(':', $id, 3);

        $package = $this->getDoctrine()->getRepository('App:Package')->findOneBy([
            'aport' => $pkgname,
            'arch' => $architecture
        ]);

        if (!$package) {
            throw new \Exception('Package "' . $pkgname . '" not found in the database');
        }

        $task = $this->getDoctrine()->getRepository('App:Queue')->findOneBy([
            'package' => $package,
            'pkgver' => $pkgver,
            'pkgrel' => $pkgrel,
        ]);

        if (!$task) {
            throw new \Exception('Package version "' . $id . '" not found in the database');
        }

        // Update time spent
        $started = $task->getTimeStarted()->getTimestamp();
        $finished = new \DateTime();
        $secs = $finished->getTimestamp() - $started;
        $task->setTimeSpent($secs);
        $manager->persist($task);

        // Update package average
        $package->setTimeSpent($package->getTimeSpent() + $secs);
        $package->setTimesBuilt($package->getTimesBuilt() + 1);
        $manager->persist($package);

        $branch = $task->getCommit()->getBranch();

        $apks = $request->files->get('file');
        foreach ($apks as $apk) {
            $component = $task->getPackage()->getComponent();

            $repository = $this->getParameter('kernel.project_dir') . '/public/repository/' . $branch;

            if (!is_dir($repository)) {
                mkdir($repository);
            }

            $repository .= '/' . $component;
            if (!is_dir($repository)) {
                mkdir($repository);
            }

            if (!is_dir($repository . '/' . $architecture)) {
                mkdir($repository . '/' . $architecture);
            }

            $apk->move($repository . '/' . $architecture . '/', $pkgname . '-' . $pkgver . '-r' . $pkgrel . '.apk');
        }
        $this->rebuildRepositoryIndex($branch, $architecture, $component);

        $task->setStatus('DONE');
        $manager->persist($task);
        $manager->flush();

        $this->get('web_log')->write('package-submit received', [
            'commit' => $commit,
            'architecture' => $architecture,
            'id' => $id
        ]);

        // Check if this completes a commit
        $commitRow = $task->getCommit();
        if ($commit->getStatus() == "BUILDING") {
            $commitTasks = $commitRow->getTasks();
            $done = 0;
            $total = count($commitTasks);
            foreach ($commitTasks as $commitTask) {
                if ($commitTask->getStatus() == 'DONE' || $commitTask->getStatus() == 'FAILED') {
                    $done++;
                }
            }
            if ($done == $total) {
                $commitRow->setStatus('SIGNING');
                $this->onCommitFinished($commitRow, $branch, $architecture);
            }
            $manager->persist($commitRow);

            $manager->flush();
        }

        $this->startNextBuild();

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * @Route("/api/signed-submit", name="signed_submit")
     */
    public function signed_submit(Request $request)
    {
        $token = $request->headers->get('X-Secret');
        $commit = $request->headers->get('X-Commit');
        list($component, $arch) = explode('-', $request->headers->get('X-Id'));

        if (!$token) {
            $this->get('web_log')->write('task-submit received without secret', null, true);
            return new JsonResponse(['error' => 'Secret missing'], 401);
        }

        if (!hash_equals(sha1($this->getParameter('kernel.secret')), $token)) {
            $this->get('web_log')->write('task-submit received with incorrect secret', null, true);
            return new JsonResponse(['error' => 'Secret not correct'], 403);
        }

        $commit = $this->getDoctrine()->getRepository('App:Commit')->findOneBy(['ref' => $commit]);

        $offlineRepository = $this->getParameter('kernel.project_dir') . '/public/offlinerepository/' . $commit->getBranch() . '/' . $component . '/' . $arch;

        $apkindex = $request->files->get('file');
        $apkindex[0]->move($offlineRepository, '/APKINDEX.tar.gz');

        $this->get('web_log')->write('signed-submit', [
            'msg' => 'Received signed submit',
        ], true);


        foreach (explode(',', $this->getParameter('rsync')) as $target) {
            // TODO: Update repository format on mirrors for multiple components
            $this->rsync($offlineRepository, $target . 'postmarketos/' . $component . '/' . $arch . '/');
        }

        $manager = $this->getDoctrine()->getManager();
        $commit->setStatus('DONE');
        $manager->persist($commit);
        $manager->flush();

        return new JsonResponse(['status' => 'ok']);
    }


    private function onCommitFinished(Commit $commit, $branch, $arch)
    {
        $components = [];
        foreach ($commit->getTasks() as $task) {
            $components[] = $task->getPackage()->getComponent();
        }
        $components = array_unique($components);

        foreach ($components as $component) {
            $repository = $this->getParameter('kernel.project_dir') . '/public/repository/' . $branch . '/' . $component . '/' . $arch;
            $offlineRepository = $this->getParameter('kernel.project_dir') . '/public/offlinerepository/' . $branch . '/' . $component . '/' . $arch;

            // Create offline copy of WIP repo
            $filesystem = new Filesystem();
            $filesystem->remove($offlineRepository);
            $filesystem->mkdir($offlineRepository);
            $filesystem->mirror($repository, $offlineRepository);
            $filesystem->remove($offlineRepository . '/APKINDEX.tar.gz');
        }

        $srht = $this->get('srht_api');
        $srht->SubmitSignJob($commit, [$arch], $components);

        //TODO: Prevent concurrent sign jobs
    }

    private function onNewPush($branch, $commit, $message)
    {
        //TODO: remove test code
        //$branch = 'master';
        //$commit = 'f93302d7c8328726c5db959341f42af1b930c78c';

        if ($branch != 'master') {
            $this->get('web_log')->write('Gitlab push is not for master', null, true);
            return 'WRONG BRANCH';
        }
        $srht = $this->get('srht_api');

        $manager = $this->getDoctrine()->getManager();

        $commitObj = $this->getDoctrine()->getRepository('App:Commit')->findOneBy(['ref' => $commit]);
        if (!$commitObj) {
            $commitObj = new Commit();
            $commitObj->setRef($commit);
            $commitObj->setBranch($branch);
            $commitObj->setMessage($message);
            $commitObj->setStatus('INDEXING');
            $manager->persist($commitObj);
        }
        $manager->flush();
        $manifest = $srht->SubmitIndexJob($commitObj);
        return $manifest;
    }

    private function createOrUpdatePackage($pkgname, $pkgver, $pkgrel, Commit $commit, $arch, $component)
    {
        $packages = $this->getDoctrine()->getRepository('App:Package');
        $queue = $this->getDoctrine()->getRepository('App:Queue');
        $manager = $this->getDoctrine()->getManager();

        // Check if the package is known already
        $package = $packages->findOneBy(['aport' => $pkgname, 'arch' => $arch]);

        // Create Package if needed
        if (!$package) {
            $package = new Package();
            $package->setAport($pkgname);
            $package->setArch($arch);
            $package->setComponent($component);
            $manager->persist($package);
        }

        // Check if there is a running or queued task for this package already
        $packageTasks = $queue->findBy([
            'package' => $package,
            'status' => ['WAITING', 'BUILDING']
        ]);

        foreach ($packageTasks as $task) {
            if ($task->getPkgver() != $pkgver || $task->getPkgrel() != $pkgrel) {
                // Task for same package but different version exists

                // Don't do anything yet for other versions
                /**
                 * if ($task->getStatus() == 'BUILDING') {
                 * // TODO: Kill existing task at sr.ht with id $existingTask->getSrhtId()
                 * }
                 * $task->setStatus('SUPERSEDED');
                 * // TODO: Re-link all queue dependencies to new queue item
                 * $manager->persist($task);
                 */
            } else {
                // Task for same package and version exists, nothing to be done
                return $task;
            }
        }

        $task = new Queue();
        $task->setPackage($package);
        $task->setPkgver($pkgver);
        $task->setPkgrel($pkgrel);
        $task->setCommit($commit);
        $task->setStatus('WAITING');
        $manager->persist($task);
        return $task;
    }

    /**
     * @Route("/api/failure-hook", name="failure_hook")
     */
    public function failure_hook(Request $request)
    {
        $payload = $request->getContent();
        $payload = json_decode($payload, true);

        $state = $payload['status'];

        $this->get('web_log')->write('failure-hook ' . $state . ' received', $payload, true);

        $manager = $this->getDoctrine()->getManager();
        $queue = $this->getDoctrine()->getRepository('App:Queue');

        // Allow resubmissions to be linked back to the original build by
        // setting the note to "resubmission,[id]"
        $task_id = (int)$payload['id'];
        if (strpos($payload['note'], 'resubmission,') === 0) {
            $part = explode(',', $payload['note'], 2);
            $task_id = (int)$part[1];
        }

        $task = $queue->findOneBy(['srhtId' => $task_id]);

        if (!$task) {
            return new Response('failure-hook: No task exists with ID "' . $task_id . '"', 400);
        }

        if ($state == 'success') {
            // Check that build result has been uploaded, just to make sure...
            if ($task->getStatus() != 'DONE') {
                // sr.ht had a oopsie
                $task->setStatus('WAITING');
                $manager->persist($task);
                $manager->flush();
                $this->startNextBuild();
                return new Response('failure-hook: Something impossible happened', 200);

            }
            return new Response('failure-hook: Actually sucess, carry on', 200);
        }

        if ($task) {
            $this->get('web_log')->write('failure-hook done', 'marking ' . $task->getPackage()->getAport() . ' as failed');
            $task->setStatus('FAILED');
            $manager->persist($task);
            $manager->flush();
        } else {
            $this->get('web_log')->write('failure-hook failure', 'No queue task found for this job');
        }
        $this->startNextBuild();
        return new Response('failure-hook: Marked "' . $task->getPackage()->getAport() . '" as failed.', 200);
    }

    private function startNextBuild()
    {
        $manager = $this->getDoctrine()->getManager();
        $queue = $this->getDoctrine()->getRepository('App:Queue');

        $running = $queue->findOneBy(['status' => 'BUILDING']);

        if ($running) {
            return;
        }

        $next = $queue->getStartable();

        $this->get('web_log')->write('next-build startables', $next);

        if (count($next) > 0) {
            $srht = $this->get('srht_api');
            $next = $next[0];
            $commit = $next->getCommit();
            $package = $next->getPackage();

            $jobToken = $package->getAport() . ':' . $next->getPkgver() . ':' . $next->getPkgrel();

            $id = $srht->SubmitBuildJob($commit, $package->getAport(), $package->getArch(), $jobToken);
            $srht->StartJob($id);
            $next->setTimeStarted(new \DateTime());
            $next->setStatus('BUILDING');
            $next->setSrhtId($id);
            $manager->persist($next);
            $manager->flush();
        }
    }

    private function rebuildRepositoryIndex($branch, $arch, $component)
    {
        $repository = $this->getParameter('kernel.project_dir') . '/public/repository/' . $branch . '/' . $component . '/' . $arch;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $binary = $this->getParameter('kernel.project_dir') . '/tools/apk.static';
        $command = $binary . ' -q index --output APKINDEX.tar.gz_ --rewrite-arch ' . $arch . ' *.apk';
        $p = proc_open($command, $descriptors, $pipes, $repository);
        if (is_resource($p)) {
            // Close stdin
            fclose($pipes[0]);

            // Get output
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            // Get stderr
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $return_value = proc_close($p);
        }

        $this->get('web_log')->write('apk-index', [
            'stdout' => $output,
            'stderr' => $errors,
            'return' => $return_value,
        ]);
        $this->signRepository($branch, $arch, $component);
    }

    private function signRepository($branch, $arch, $component)
    {
        $repository = $this->getParameter('kernel.project_dir') . '/public/repository/' . $branch . '/' . $component . '/' . $arch;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $binary = $this->getParameter('kernel.project_dir') . '/tools/abuild-sign.noinclude';
        $privkey = $this->getParameter('kernel.project_dir') . '/private/build@postmarketos.org.rsa';
        $index = $repository . '/APKINDEX.tar.gz_';
        $command = $binary . ' -p build@postmarketos.org.pub -k "' . $privkey . '" "' . $index . '"';
        $p = proc_open($command, $descriptors, $pipes, $repository);
        if (is_resource($p)) {
            // Close stdin
            fclose($pipes[0]);

            // Get output
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            // Get stderr
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $return_value = proc_close($p);
        }

        $this->get('web_log')->write('apk-index', [
            'stdout' => $output,
            'stderr' => $errors,
            'return' => $return_value,
        ]);

        $finalIndex = str_replace('.tar.gz_', '.tar.gz', $index);
        rename($index, $finalIndex);
    }

    private function rsync($source, $destination)
    {
        $command = [
            'rsync',
            '--links',
            '--human-readable',
            '--recursive',
            '--size-only',
            '--info=DEL,NAME,SKIP,STATS',
            '--delete',
        ];
        if (strpos($destination, '@') !== false) {
            $command[] = '-e "ssh -i ' . $this->getParameter('rsync_key') . '"';
        }
        $command[] = $source . '/*';
        $command[] = $destination;
        $command = implode(" ", $command);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $p = proc_open($command, $descriptors, $pipes);
        if (is_resource($p)) {
            // Close stdin
            fclose($pipes[0]);

            // Get output
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            // Get stderr
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $return_value = proc_close($p);

        }

        $log = $command . PHP_EOL . PHP_EOL . $output . PHP_EOL . $errors;
        $this->get('web_log')->write('rsync', $log);
    }
}
