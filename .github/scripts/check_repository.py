#!/usr/bin/env python3
"""Validate the public overview and its distribution boundaries, without dependencies."""
from __future__ import annotations

import re
import json
import struct
import subprocess
import tarfile
import tempfile
import xml.etree.ElementTree as ET
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import unquote, urlsplit

ROOT = Path(__file__).resolve().parents[2]
RAW = "https://raw.githubusercontent.com/janczakb/media-studio/main/"


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ValueError(message)


def git(directory: Path, *args: str) -> str:
    result = subprocess.run(
        ["git", "-C", str(directory), *args], check=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
    )
    return result.stdout


def slug(text: str) -> str:
    return re.sub(r"[^a-z0-9_ -]", "", text.lower()).replace(" ", "-")


class Readme(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.assets: list[str] = []
        self.anchors: list[str] = []
        self.hero_hidden = False
        self.h1_count = 0

    def handle_starttag(self, tag: str, attributes: list[tuple[str, str | None]]) -> None:
        attributes = dict(attributes)
        if tag == "h1":
            self.h1_count += 1
        if tag == "a" and attributes.get("href", "").startswith("#"):
            self.anchors.append(attributes["href"][1:])
        for key in ("src", "srcset"):
            value = attributes.get(key)
            if value:
                self.assets.append(value)
        if tag == "img" and (attributes.get("src") or "").endswith("/media-studio-product.webp"):
            self.hero_hidden = "filament-hidden" in (attributes.get("class") or "").split()


def check_readme() -> None:
    text = (ROOT / "README.md").read_text(encoding="utf-8")
    parser = Readme()
    parser.feed(text)
    require(parser.h1_count == 1, "README must have one product heading")
    require(parser.hero_hidden, "Mark the hero image itself as filament-hidden")
    headings = {slug(value) for value in re.findall(r"^#{1,6} (.+)$", text, re.M)}
    anchors = parser.anchors + re.findall(r"\]\(#([^)]*)\)", text)
    for anchor in anchors:
        require(anchor in headings, f"Missing README heading: {anchor}")
    require(parser.assets, "README must include its presentation assets")
    for value in parser.assets:
        if value.startswith(RAW):
            relative = unquote(urlsplit(value).path.split("/main/", 1)[1])
            path = (ROOT / relative).resolve()
            require(path.is_relative_to(ROOT), "Asset escapes the repository")
            require(path.is_file(), f"Missing presentation asset: {relative}")
        else:
            require(value.startswith("https://"), f"Use absolute HTTPS image URLs: {value}")
    print("PASS: README headings, navigation, graphics and Filament banner compatibility")


def webp_dimensions(path: Path) -> tuple[int, int]:
    with path.open("rb") as source:
        data = source.read(64)
    require(data[:4] == b"RIFF" and data[8:12] == b"WEBP", f"Invalid WebP: {path}")
    kind = data[12:16]
    if kind == b"VP8X":
        return int.from_bytes(data[24:27], "little") + 1, int.from_bytes(data[27:30], "little") + 1
    if kind == b"VP8 ":
        require(data[23:26] == b"\x9d\x01\x2a", f"Invalid VP8 frame: {path}")
        width, height = struct.unpack("<HH", data[26:30])
        return width & 0x3FFF, height & 0x3FFF
    if kind == b"VP8L":
        require(data[20] == 0x2F, f"Invalid VP8L frame: {path}")
        bits = int.from_bytes(data[21:25], "little")
        return (bits & 0x3FFF) + 1, ((bits >> 14) & 0x3FFF) + 1
    raise ValueError(f"Unsupported WebP header: {path}")


def check_artwork() -> None:
    for path in (ROOT / "art").rglob("*.webp"):
        width, height = webp_dimensions(path)
        require(width > 0 and height > 0, f"Empty image: {path}")
    width, height = webp_dimensions(ROOT / "art/media-studio-product.webp")
    require(width >= 2560 and width * 9 == height * 16, "Hero must be at least 2560px and 16:9")
    for path in (ROOT / "art").rglob("*.svg"):
        tree = ET.parse(path)
        for element in tree.iter():
            require(element.tag.rsplit("}", 1)[-1] not in {"script", "foreignObject"}, f"Executable SVG: {path}")
            for key, value in element.attrib.items():
                name = key.rsplit("}", 1)[-1]
                require(not name.lower().startswith("on"), f"SVG event handler: {path}")
                if name == "href":
                    require(value.startswith("#"), f"External SVG reference: {path}")
    print("PASS: WebP dimensions, banner aspect ratio and static SVG assets")


def check_workflows() -> None:
    for path in (ROOT / ".github/workflows").glob("*.yml"):
        text = path.read_text(encoding="utf-8")
        require("pull_request_target" not in text, f"Unsafe pull request execution context: {path}")
        require("permissions:\n  contents: read" in text, f"Missing read-only workflow default: {path}")
        for action in re.findall(r"^\s*uses:\s*(\S+)", text, re.M):
            require(bool(re.fullmatch(r"[\w.-]+/[\w./-]+@[0-9a-f]{40}", action)), f"Action must use a full SHA: {action}")
    funding = (ROOT / ".github/FUNDING.yml").read_text(encoding="utf-8")
    require("github: janczakb" in funding and "buy_me_a_coffee: janczakb" in funding, "Unexpected sponsor destinations")
    print("PASS: pinned Actions, safe execution context, permissions and sponsor destinations")


def archive_names(directory: Path, destination: Path) -> set[str]:
    with destination.open("wb") as output:
        subprocess.run(["git", "-C", str(directory), "archive", "--format=tar", "HEAD"], stdout=output, check=True)
    with tarfile.open(destination) as archive:
        return {member.name for member in archive if member.isfile()}


def check_archive() -> None:
    forbidden = {"art", "docs", ".github", ".internal", "tests", "examples", "example", "dumps", "scripts", "vendor", "node_modules"}
    with tempfile.TemporaryDirectory(prefix="studio-dist-check-") as temporary:
        temporary = Path(temporary)
        names = archive_names(ROOT, temporary / "current.tar")
        require("README.md" in names, "The repository archive must keep its README")
        for name in names:
            require(name.split("/", 1)[0] not in forbidden, f"Repository material leaked into archive: {name}")
        # Exercise the policy even while package source is intentionally unpublished.
        fixture = temporary / "fixture"
        fixture.mkdir()
        (fixture / ".gitattributes").write_bytes((ROOT / ".gitattributes").read_bytes())
        required = {
            "composer.json", "LICENSE", "COMMERCIAL.md", "CREDITS.md", "VERSION", "src/MediaStudioServiceProvider.php",
            "config/media-studio.php", "database/migrations/create_media.php", "lang/en/messages.php",
            "resources/views/picker.blade.php", "resources/boost/guidelines/core.blade.php",
            "resources/boost/skills/media-studio-development/SKILL.md",
            "packages/laravel/src/MediaStudioServiceProvider.php", "packages/media-core/src/Media.php",
            "packages/laravel/resources/boost/skills/media-studio-development/SKILL.md",
            "packages/react/dist/studio.js", "packages/react/dist/studio.css",
        }
        excluded = {f"{directory}/example.txt" for directory in forbidden}
        excluded |= {"AGENTS.md", "composer.lock", ".env", ".env.production", "package.json", "package-lock.json", "packages/react/src/index.tsx", "packages/react/node_modules/test/index.js", "packages/browser-extension/src/main.ts"}
        for name in required | excluded:
            path = fixture / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("archive fixture\n", encoding="utf-8")
        git(fixture, "init", "--quiet")
        git(fixture, "add", ".")
        git(fixture, "-c", "user.name=Repository checks", "-c", "user.email=checks@example.test", "commit", "--quiet", "-m", "Archive policy fixture")
        exported = archive_names(fixture, temporary / "fixture.tar")
        require(required <= exported, f"Missing runtime files: {sorted(required - exported)}")
        require(not excluded & exported, f"Development files exported: {sorted(excluded & exported)}")
    print("PASS: real Git archive exclusions and runtime/Boost/build preservation")


def check_publication_boundary() -> None:
    policy = json.loads((ROOT / ".github/publication-allowlist.json").read_text(encoding="utf-8"))
    files = git(ROOT, "ls-files", "-z").split("\0")
    private = {".internal", ".ai", ".agents", ".codex", ".claude", ".cursor", "dumps", "scratch", "tmp", "temp", "vendor", "node_modules", "__pycache__"}
    for name in filter(None, files):
        path = Path(name)
        require(not private.intersection(path.parts), f"Private folder in publication: {name}")
        require(not any(part.startswith(".env") or part in {"auth.json", ".npmrc"} for part in path.parts), f"Private configuration in publication: {name}")
        require(path.suffix.lower() not in {".sql", ".sqlite", ".sqlite3", ".log", ".pyc"}, f"Internal data file in publication: {name}")
        if len(path.parts) == 1:
            require(name in policy["root_files"], f"Root file is not approved for publication: {name}")
        else:
            require(path.parts[0] in policy["directories"], f"Directory is not approved for publication: {name}")
    print("PASS: explicit public-file allowlist; private folders and package source excluded")


def main() -> None:
    check_publication_boundary()
    check_readme()
    check_artwork()
    check_workflows()
    check_archive()


if __name__ == "__main__":
    main()
