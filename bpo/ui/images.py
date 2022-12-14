# Copyright 2022 Oliver Smith
# SPDX-License-Identifier: AGPL-3.0-or-later
import glob
import json
import logging
import os
import shutil
from datetime import datetime

import bpo.helpers.job
import bpo.images


def get_entries(path, reverse=False):
    """ Get a sorted list of entries (files, directories) in a given path.
        :param reverse: order in reverse
        :returns: list of entries """
    ret = []
    for entry in list(sorted(glob.glob(f"{path}/*"), reverse=reverse)):
        basename = os.path.basename(entry)
        if basename in ["index.html", "index.json"]:
            continue
        ret.append(basename)
    return ret


def get_file_size_human(path):
    """ Get human readable size of path """
    size = os.path.getsize(path)
    for unit in ["", "Ki", "Mi", "Gi"]:
        if abs(size) < 1024.0 or unit == "Gi":
            break
        size /= 1024.0
    size = round(size, 1)
    return f"{size} {unit}B"


def file_entry_add_checksums(entry, path):
    """ Find checksum files generated by the sha256sum/sha512sum tools, and
        if they exist, add them to the "entry" dict.
        :param entry: dict where the checksum will be added
        :param path: to the file, with {path}.sha256 and {path}.sha512 files
                     in the same dir """
    for checksum in ["sha256", "sha512"]:
        checksum_path = f"{path}.{checksum}"
        if not os.path.exists(checksum_path):
            continue
        with open(checksum_path) as handle:
            entry[checksum] = handle.read().strip().split(' ')[0]


def write_index(path, template, **context):
    """ Write an index.html in the specified path based on the supplied
        template
        :param path: full path to the directory for the index.html
        :param template: template filename
        :param context: arguments for the template """
    # Title
    relpath = os.path.relpath(path, bpo.config.args.images_path)
    title = "postmarketOS // Official Images"
    if relpath != ".":
        title = f"{relpath} - {title}"
    context["title"] = title

    # Navigation with format: [(url1, dirname1), (url2, dirname2), ...]
    navigation = [(bpo.config.args.url_images, "bpo")]
    if relpath != ".":
        relpath_split = relpath.split("/")
        for i, dirname in enumerate(relpath_split):
            url = "../" * (len(relpath_split) - i - 1)
            navigation.append([url, dirname])
    context["navigation"] = navigation

    context["bpo_url"] = bpo.config.const.url
    context["img_url"] = bpo.config.args.url_images

    if "entries" not in context:
        context["entries"] = get_entries(path)

    template = bpo.ui.env.get_template(f"images/{template}")
    html = template.render(**context)
    with open(os.path.join(path, "index.html"), "w") as handle:
        handle.write(html)


def write_index_file_list(path, template):
    """ Write the index.html for the file list of one build. Each file in the
        directory gets metadata attached as it gets passed to the template
        (file size, checksums).
        :param path: full path to the directory for the index.html
        :param template: template filename """
    image = bpo.images.db_obj_from_path(path)
    job_link = bpo.helpers.job.get_link(image.job_id)
    codename = os.path.basename(os.path.dirname(os.path.dirname(path[:-1])))

    # Add metadata to each entry
    # entries = [{name: "20210612-0119-postmarketO...line-modem.img.xz",
    #             sha256: "24892374982374...",
    #             sha512: "5464256432354...",
    #             size: "1337 MiB"},
    #             {...}, ...]
    entries = []
    for name in get_entries(path):
        if name.endswith(".sha256") or name.endswith(".sha512"):
            continue

        entry = {"name": name,
                 "size": get_file_size_human(f"{path}/{name}")}
        file_entry_add_checksums(entry, f"{path}/{name}")
        entries.append(entry)

    write_index(path, template, entries=entries, job_link=job_link,
                codename=codename)


def parse_files_from_disk():
    """ Iterate through images on disk and generate an intermediate format
    using the path info """
    # Structure:
    # {"edge": {                            // release
    #   "nokia-n900": {                     // device
    #     "i3wm": {                         // user interface
    #       "20210615-0558": {              // image date
    #         "2021...n900.img.xz": {       // file name
    #           "size":   "12834723847",    // size in bytes
    #           "sha256": "23428937489...", // checksum sha256
    #           "sha512": "23428937489...", // checksum sha512
    #           "url": "https://....",      // download url for image
    #          }, ...},                     // more files in same date dir
    #       ...},                           // more dates
    #     ...},                             // more user interfaces
    #   ...},                               // more devices
    # ...}                                  // more releases
    index = {}
    images_path = bpo.config.args.images_path

    for path in glob.iglob(f"{images_path}/*/*/*/*/*"):
        # Checksums are not listed as separate files
        if path.endswith(".sha256") or path.endswith(".sha512") \
                or path.endswith(".html"):
            continue

        relpath = os.path.relpath(path, images_path)
        release, device, ui, date, filename = relpath.split("/")

        if release not in index:
            index[release] = {}
        if device not in index[release]:
            index[release][device] = {}
        if ui not in index[release][device]:
            index[release][device][ui] = {}
        if date not in index[release][device][ui]:
            index[release][device][ui][date] = {}

        entry = {
            "size": os.path.getsize(path),
            "url": f"{bpo.config.args.url_images}/{relpath}"
        }
        file_entry_add_checksums(entry, path)
        index[release][device][ui][date][filename] = entry

    return index


def write_index_json():
    """ Write an index.json file, which can be used by a desktop installer to
        list and download available images. """
    logging.info("Writing index.json to images dir")

    # The index.json written by this method is in the following format:
    # { "releases": [                                     // list of releases
    #   { "name": "edge",                                 // release name
    #     "devices": [                                    // list of devices
    #       { "name": "qemu-amd64",                       // device name
    #         "interfaces": [                             // list of device UIs
    #           { "name": "phosh",                        // name of UI
    #             "images": [                             // list of UI images
    #               {
    #                 "name": "202...virt.img.xz",        // file name
    #                 "timestamp": "2021-...T12:20:00",   // date in ISO 8601
    #                 "size": 1245415,                    // size in bytes
    #                 "url": "https://...",               // image download url
    #                 "sha256": "d000...",                // sha256
    #                 "sha512": "7ab1..."                 // sha512
    #               }, {...} ]
    #           }, {...} ]
    #       }, {...} ]
    #   }, {...} ]
    # }
    # the full schema for this JSON is at test/testdata/index.schema.json

    images_path = bpo.config.args.images_path
    fs_index = parse_files_from_disk()

    releases = []
    index = {"releases": releases}
    for release_name, d in fs_index.items():
        devices = []
        release = {
            "name": release_name,
            "devices": devices,
        }
        releases.append(release)
        for device_name, u in d.items():
            interfaces = []
            dev = {
                "name": device_name,
                "interfaces": interfaces,
            }
            devices.append(dev)
            for ui_name, i in u.items():
                images = []
                interface = {
                    "name": ui_name,
                    "images": images,
                }
                interfaces.append(interface)
                for date, l in i.items():
                    date_iso = datetime.strptime(date,
                                                 '%Y%m%d-%H%M').isoformat()
                    for image_name, i in l.items():
                        image = {
                            "name": image_name,
                            "timestamp": date_iso,
                            "size": i["size"],
                            "url": i["url"]
                        }
                        # these are optional
                        for s in ["sha256", "sha512"]:
                            if s in i:
                                image[s] = i[s]
                        images.append(image)

    os.makedirs(bpo.config.args.images_path, exist_ok=True)
    path_json = f"{images_path}/index.json"
    with open(f"{path_json}.new", "w") as handle:
        handle.write(json.dumps(index, indent=4))
    shutil.move(f"{path_json}.new", path_json)


def write_index_html():
    """ For each directory in the images dir (recursively), write the HTML
        files. The files are always overwritten. """
    logging.info("Writing html files to images dir")

    for path in glob.iglob(f"{bpo.config.args.images_path}/**/",
                           recursive=True):
        relpath = os.path.relpath(path, bpo.config.args.images_path)

        if relpath == ".":
            entries = get_entries(path, True)
            if "edge" in entries:
                entries.remove("edge")
                entries = ["edge"] + entries
            write_index(path, "01_releases.html", entries=entries)
        elif relpath.count('/') == 0:
            write_index(path, "02_devices.html")
        elif relpath.count('/') == 1:
            write_index(path, "03_userinterfaces.html")
        elif relpath.count('/') == 2:
            entries = get_entries(path, True)
            write_index(path, "04_dates.html", entries=entries)
        else:
            write_index_file_list(path, "05_files.html")


def write_index_all():
    write_index_json()
    write_index_html()
