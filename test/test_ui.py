# Copyright 2020 Oliver Smith
# SPDX-License-Identifier: AGPL-3.0-or-later
""" Testing bpo/ui/__init__.py """
import bpo_test
import bpo_test.trigger
import bpo.config.const
import bpo.db
import bpo.repo
import bpo.ui


def test_update_badge(monkeypatch):
    monkeypatch.setattr(bpo.config.const, "branches", ["master"])
    monkeypatch.setattr(bpo.config.const, "branches_ignore_errors", [])

    # Fill the db with "hello-world", "hello-world-wrapper"
    with bpo_test.BPOServer():
        monkeypatch.setattr(bpo.repo, "build", bpo_test.stop_server)
        bpo_test.trigger.job_callback_get_depends("master")

    session = bpo.db.session()
    func = bpo.ui.update_badge
    func_pkgs = bpo.db.get_all_packages_by_status
    arch = "x86_64"
    branch = "master"

    # Building
    assert func(session, func_pkgs(session)) == "building"

    # Failed
    pkg_hello = bpo.db.get_package(session, "hello-world", arch, branch)
    bpo.db.set_package_status(session, pkg_hello, bpo.db.PackageStatus.failed)
    assert func(session, func_pkgs(session)) == "failed"

    # Up-to-date
    pkg_wrapper = bpo.db.get_package(session, "hello-world-wrapper", arch,
                                     branch)
    bpo.db.set_package_status(session, pkg_hello, bpo.db.PackageStatus.built)
    bpo.db.set_package_status(session, pkg_wrapper, bpo.db.PackageStatus.built)
    assert func(session, func_pkgs(session)) == "up-to-date"

    # hello-world-wrapper: change branch, set to failed
    pkg_wrapper.branch = "v20.05"
    pkg_wrapper.status = bpo.db.PackageStatus.failed
    session.merge(pkg_wrapper)
    session.commit()

    # Branch is not in config: still up-to-date
    assert func(session, func_pkgs(session)) == "up-to-date"

    # Branch is in config: failed
    monkeypatch.setattr(bpo.config.const, "branches", ["master", "v20.05"])
    assert func(session, func_pkgs(session)) == "failed"

    # Branch is ignored: up-to-date
    monkeypatch.setattr(bpo.config.const, "branches_ignore_errors", ["v20.05"])
    assert func(session, func_pkgs(session)) == "up-to-date"
