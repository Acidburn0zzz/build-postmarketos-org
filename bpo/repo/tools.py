# Copyright 2020 Oliver Smith
# SPDX-License-Identifier: AGPL-3.0-or-later

import glob
import logging
import os
import subprocess
import shutil
import tarfile
import datetime

import bpo.config.const
import bpo.repo


def temp_path_prepare():
    temp_path = bpo.config.args.temp_path + "/repo_tools"
    if os.path.exists(temp_path):
        subprocess.run(["rm", "-rf", temp_path], check=True)
    os.makedirs(temp_path + "/bin", exist_ok=True)


def extract_tool_apk(pkgname, paths):
    bin_path = bpo.config.args.temp_path + "/repo_tools/bin"
    pattern = bpo.config.const.top_dir + "/data/tools/" + pkgname + "-*.apk"
    results = glob.glob(pattern)
    if len(results) != 1:
        raise RuntimeError("There must be exactly one file that matches: " +
                           pattern)

    with tarfile.open(results[0], "r:gz") as tar:
        for path in paths:
            logging.debug("Extracting " + results[0] + ": " + path)
            extract_path = bin_path + "/" + os.path.basename(path)
            with open(extract_path, "w+b") as handle:
                member = tar.getmember(path)
                shutil.copyfileobj(tar.extractfile(member), handle)
                os.chmod(extract_path, 0o755)


def init():
    temp_path_prepare()
    extract_tool_apk("apk-tools-static", ["sbin/apk.static"])
    extract_tool_apk("abuild-sign-noinclude", ["usr/bin/abuild-sign.noinclude",
                                               "usr/bin/abuild-tar.static"])


def run(arch, branch, repo_name, cwd, cmd):
    """ Run a tool with a nice log message and a proper PATH.
        :param cwd: current working dir, where cmd should get executed
        :param cmd: the command to execute

        All other parameters (arch, branch, repo_name) are just for printing a
        nice log message. """
    tools_bin = bpo.config.args.temp_path + "/repo_tools/bin"
    env = {"PATH": tools_bin + ":" + os.getenv("PATH")}

    logging.debug("{}/{}: running in {} repo: {}".format(branch, arch,
                                                         repo_name, cmd))
    subprocess.run(cmd, cwd=cwd, env=env, check=True)


def index(arch, branch, repo_name, cwd):
    """ Sign a repository.
        :param cwd: path to the repository """

    # aports-turbo, hosted at pkgs.postmarketos.org, uses the description to
    # check if the APKINDEX was modified. Set it to the current date to make
    # that check work.
    description = str(datetime.datetime.utcnow())

    cmd = ["apk.static", "-q", "index", "--output", "APKINDEX.tar.gz",
           "--rewrite-arch", arch,
           "--description", description] + bpo.repo.get_apks(cwd)
    bpo.repo.tools.run(arch, branch, repo_name, cwd, cmd)
