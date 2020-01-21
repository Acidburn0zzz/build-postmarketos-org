# Copyright 2020 Oliver Smith
# SPDX-License-Identifier: AGPL-3.0-or-later

import bpo.config.const
import bpo_test
import json
import os
import requests
import sys

# Add test dir to import path (so we can import bpo_test)
topdir = os.path.realpath(os.path.join(os.path.dirname(__file__) + "/.."))
sys.path.insert(0, topdir)


def api_request(path, headers, payload=None, files=None):
    """ Send one HTTP request to the bpo server's API and stop the test if the
        request fails. """
    ret = requests.post("http://127.0.0.1:5000/api/" + path, headers=headers,
                        json=payload, files=files)
    if not ret.ok:
        bpo_test.stop_server_nok()


def push_hook_gitlab():
    token = bpo.config.const.test_tokens["push_hook_gitlab"]
    headers = {"X-Gitlab-Token": token}
    payload = {"object_kind": "push",
               "ref": "refs/heads/master",
               "checkout_sha": "deadbeef",
               "commits":
               [{"id": "5e9e102a00e58541ed91164de15fd209af628b42",
                 "message": "main/postmarketos-ui-phosh: clean-up\n",
                 "timestamp": "2019-05-25T16:23:30Z",
                 "url": "https://gitlab.com/...d91164de15fd209af628b42",
                 "author": {"name": "John Doe", "email": "john@localhost"},
                 "added": [],
                 "modified": ["main/postmarketos-ui-phosh/APKBUILD"],
                 "removed": []}]}
    api_request("push-hook/gitlab", headers, payload)


def override_depends_json(output, overrides,
                          testfile="depends.master.x86_64.json"):
    """ Override values in the payload for /api/job-callback/get-depends.
        :param output: where to store the modified json
        :param testfile: original json in test/testdata
        :param overrides: dict of what should be changed, e.g.
                          {"hello-world": {"version": "1-r5"},
                           "hello-world-wrapper": {...}} """
    print("{}: creating from {}".format(output, testfile))
    file_path = (bpo.config.const.top_dir + "/test/testdata/" + testfile)
    with open(file_path, "r") as handle:
        content = json.load(handle)

    for pkgname, keys_values in overrides.items():
        for key, value in keys_values.items():
            found = False
            for entry in content:
                if entry["pkgname"] != pkgname:
                    continue
                found = True
                if entry[key] != value:
                    print("{}[{}][{}]: replaced '{}' with '{}'".format(output,
                          pkgname, key, entry[key], value))
                    entry[key] = value
                break

            if not found:
                raise RuntimeError("pkgname {} not found in {}!".
                                   format(pkgname, file_path))

    with open(output, "w") as handle:
        handle.write(json.dumps(content, indent=4))


def job_callback_get_depends(testfile="depends.master.x86_64.json",
                             file_name="depends.master.x86_64.json"):
    """ Note that the versions must match the current versions in pmaports.git,
        otherwise the bpo server will build the current packages and complain
        later on, that the version isn't matching. """
    token = bpo.config.const.test_tokens["job_callback"]
    headers = {"X-BPO-Job-Id": "1",
               "X-BPO-Token": token}

    # master/x86_64: "hello-world", "hello-world-wrapper"
    file_path = testfile
    if not os.path.exists(file_path):
        file_path = (bpo.config.const.top_dir + "/test/testdata/" + testfile)
    files = [("file[]", (file_name, open(file_path, "rb"),
                         "application/octet-stream"))]

    # Other branches and arches: no packages (simplifies tests)
    file_path = bpo.config.const.top_dir + "/test/testdata/empty_list.json"
    for branch in bpo.config.const.branches:
        for arch in bpo.config.const.architectures:
            if branch == "master" and arch == "x86_64":
                continue
            file_name = "depends." + branch + "." + arch + ".json"
            files.append(("file[]", (file_name, open(file_path, "rb"),
                                     "application/octet-stream")))

    api_request("job-callback/get-depends", headers, files=files)
