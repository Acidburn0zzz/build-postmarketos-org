<?php

namespace App\Controller;

use App\Entity\Commit;
use App\Entity\Package;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class StatusController extends Controller
{
    /**
     * @Route("/", name="status")
     * @Route("/queue", name="queue")
     */
    public function queue()
    {
        $queue = $this->getDoctrine()->getRepository('App:Queue');
        $queued = $queue->findBy(['status' => ['WAITING', 'BUILDING']], ['id' => 'DESC']);
        $done = $queue->findBy(['status' => ['DONE', 'FAILED', 'SUPERSEDED']], ['id' => 'DESC'], 50);
        return $this->render('status/queue.html.twig', [
            'queued' => $queued,
            'done' => $done
        ]);
    }

    /**
     * @Route("/packages", name="packages")
     */
    public function packages()
    {
        $packages = $this->getDoctrine()->getRepository('App:Package')->findBy([], ['aport' => 'ASC']);
        return $this->render('status/packages.html.twig', [
            'packages' => $packages,
        ]);
    }

    /**
     * @Route("/commits", name="commits")
     */
    public function commits()
    {
        $queue = $this->getDoctrine()->getRepository('App:Commit');

        $raw = $queue->findBy([], ['id' => 'DESC'], 75);
        $queued = [];
        $done = [];

        foreach ($raw as $r) {
            if (in_array($r->getStatus(), ['DONE', 'FAILED', 'SUPERSEDED'])) {
                $done[] = $r;
            } else {
                $queued[] = $r;
            }
        }
        return $this->render('status/commits.html.twig', [
            'queued' => $queued,
            'done' => $done
        ]);
    }

    /**
     * @Route("/commits/{ref}", name="commit_details")
     */
    public function commitDetails($ref)
    {
        $commits = $this->getDoctrine()->getRepository('App:Commit');
        $commit = $commits->findOneBy(['ref' => $ref]);
        /** @var Commit $commit */
        $tasks = $commit->getTasks();

        $graph = 'digraph g {' . PHP_EOL . '    rankdir=LR;' . PHP_EOL;
        foreach ($tasks as $task) {
            $package = $task->getPackage();
            $label = $package->getAport() . ' ' . $task->getPkgver() . '-r' . $task->getPkgrel();
            switch ($task->getStatus()) {
                case "WAITING":
                    $color = 'azure1';
                    break;
                case "BUILDING":
                    $color = 'azure3';
                    break;
                case "FAILED":
                    $color = 'tomato';
                    break;
                case "DONE":
                    $color = 'darkolivegreen1';
                    break;
                case "SUPERSEDED":
                    $color = 'blanchedalmond'; //because why not
                    break;
            }
            $graph .= '    "' . $package->getAport() . '"[label="' . $label . '" fillcolor=' . $color . ' style=filled]' . PHP_EOL;
        }
        $graph .= PHP_EOL . PHP_EOL;
        foreach ($tasks as $task) {
            $package = $task->getPackage();
            foreach ($package->getQueueDependencies() as $dependency) {
                $graph .= '    "' . $dependency->getRequirement()->getAport() . '" -> "' . $package->getAport() . '"' . PHP_EOL;
            }
        }
        $graph .= ' }';

        $inFile = $this->getParameter('kernel.project_dir') . '/public/commit/' . $ref . '.dot';
        $outFile = $this->getParameter('kernel.project_dir') . '/public/commit/' . $ref . '.png';
        file_put_contents($inFile, $graph);
        exec('dot "' . $inFile . '" -o "' . $outFile . '" -Tpng');

        return $this->render('status/commit.html.twig', ['ref' => $ref]);
    }

    /**
     * @Route("/log", name="log")
     */
    public function log()
    {
        $log = $this->getDoctrine()->getRepository('App:Log')->findBy([], ['datetime' => 'DESC']);
        return $this->render('status/log.html.twig', [
            'log' => $log,
        ]);
    }
}
