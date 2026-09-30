"""User-generated text: normalisation, validation, and entity extraction.

UGC is *plaintext*. There is no HTML, no Markdown. The server splits text into typed
segments that the client renders with ``textContent`` and ``createElement('a')``:

    [{"t": "text", "v": "hello "}, {"t": "mention", "v": "@bob", "handle": "bob"},
     {"t": "link", "v": "example.com/x", "url": "https://example.com/x"}, {"t": "tag", "v": "#hi", "tag": "hi"}]

Because the client never parses markup, stored XSS via HTML/Markdown/attribute breaking has no
sink to reach (THREAT-MODEL I-4). Every regex below is linear-time (no nested quantifiers) and
runs on length-capped input (D-5 ReDoS).
"""
from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass, field
from urllib.parse import urlsplit, urlunsplit

# Bidi overrides/isolates (Trojan-Source-style spoofing), zero-width chars (invisible text),
# BOM, and interlinear annotation marks. ZWJ (U+200D) is kept because emoji sequences need it.
_INVISIBLE = dict.fromkeys(
    [*range(0x202A, 0x202F), *range(0x2066, 0x206A), 0x200B, 0x200C, 0x200E, 0x200F, 0x2060,
     0xFEFF, 0xFFF9, 0xFFFA, 0xFFFB, 0x180E, 0x061C],
    None,
)

HANDLE_RE = re.compile(r"^[A-Za-z0-9_]{3,30}$")
RESERVED_HANDLES = frozenset({
    "admin", "administrator", "root", "system", "support", "help", "security", "moderator", "mod",
    "staff", "official", "api", "www", "mail", "bastion", "settings", "login", "logout", "register",
    "signup", "about", "terms", "privacy", "null", "undefined", "me", "notifications", "messages",
    "search", "explore", "home", "static", "assets", "media", "abuse", "postmaster", "hostmaster",
})

_HASHTAG = re.compile(r"(?<![\w#&])#(\w{1,64})")
_MENTION = re.compile(r"(?<![\w@])@([A-Za-z0-9_]{3,30})\b")
_URL = re.compile(r"\bhttps?://[^\s<>\"'`]{1,2000}", re.IGNORECASE)
_TRAILING = ".,!?;:'\")]}"

MAX_HASHTAGS = 10
MAX_MENTIONS = 10
MAX_LINKS = 5


class TextError(ValueError):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code
        self.message = message


def normalize(text: str, *, multiline: bool) -> str:
    text = unicodedata.normalize("NFC", text).translate(_INVISIBLE)
    out = []
    for ch in text:
        cat = unicodedata.category(ch)
        if ch == "\n" and multiline:
            out.append(ch)
        elif ch in "\r\t\n":
            out.append(" " if ch != "\r" else "")
        elif cat in ("Cc", "Cs", "Co", "Cn") or (cat == "Cf" and ch != "‍"):
            continue  # control, surrogate, private-use, unassigned, other format chars
        else:
            out.append(ch)
    s = "".join(out)
    if multiline:
        s = re.sub(r"\n{3,}", "\n\n", s)
        s = "\n".join(line.rstrip() for line in s.split("\n"))
    else:
        s = re.sub(r" {2,}", " ", s)
    return s.strip()


def validate_handle(handle: str) -> str:
    if not HANDLE_RE.fullmatch(handle):
        raise TextError("invalid_handle", "Handles are 3-30 characters: letters, digits and underscore.")
    if handle.lower() in RESERVED_HANDLES:
        raise TextError("handle_reserved", "That handle is reserved.")
    return handle


def normalize_url(raw: str) -> str | None:
    """Returns a canonical http(s) URL, or None if unsafe/invalid."""
    try:
        parts = urlsplit(raw)
    except ValueError:
        return None
    if parts.scheme.lower() not in ("http", "https"):
        return None
    if parts.username or parts.password:  # https://paypal.com@evil.example phishing trick
        return None
    host = parts.hostname
    if not host or len(host) > 253:
        return None
    try:
        ascii_host = host.encode("idna").decode("ascii").lower()
    except UnicodeError:
        return None
    if not re.fullmatch(r"[a-z0-9.-]+", ascii_host) or "." not in ascii_host or ascii_host.startswith("."):
        return None
    try:
        port = parts.port
    except ValueError:
        return None
    netloc = ascii_host + (f":{port}" if port else "")
    return urlunsplit((parts.scheme.lower(), netloc, parts.path, parts.query, ""))


def url_host(url: str) -> str:
    return (urlsplit(url).hostname or "").lower()


def _display_url(url: str) -> str:
    p = urlsplit(url)
    d = (p.hostname or "") + (p.path if p.path not in ("", "/") else "")
    return d if len(d) <= 40 else d[:39] + "…"


@dataclass
class Parsed:
    text: str
    segments: list[dict] = field(default_factory=list)
    hashtags: list[str] = field(default_factory=list)
    mentions: list[str] = field(default_factory=list)  # lower-cased handles
    links: list[str] = field(default_factory=list)


def parse(text: str, *, max_len: int) -> Parsed:
    text = normalize(text, multiline=True)
    if not text:
        raise TextError("empty", "Text must not be empty.")
    if len(text) > max_len:
        raise TextError("too_long", f"Text must be at most {max_len} characters.")

    spans: list[tuple[int, int, dict]] = []
    for m in _URL.finditer(text):
        raw = m.group(0).rstrip(_TRAILING)
        url = normalize_url(raw)
        if url:
            spans.append((m.start(), m.start() + len(raw), {"t": "link", "v": _display_url(url), "url": url}))
    taken = [(a, b) for a, b, _ in spans]

    def free(a: int, b: int) -> bool:
        return all(b <= x or a >= y for x, y in taken)

    for m in _MENTION.finditer(text):
        if free(m.start(), m.end()):
            spans.append((m.start(), m.end(), {"t": "mention", "v": m.group(0), "handle": m.group(1).lower()}))
            taken.append((m.start(), m.end()))
    for m in _HASHTAG.finditer(text):
        if free(m.start(), m.end()):
            tag = m.group(1).lower()
            spans.append((m.start(), m.end(), {"t": "tag", "v": m.group(0), "tag": tag}))
            taken.append((m.start(), m.end()))
    spans.sort(key=lambda s: s[0])

    p = Parsed(text=text)
    pos = 0
    for a, b, seg in spans:
        if a > pos:
            p.segments.append({"t": "text", "v": text[pos:a]})
        p.segments.append(seg)
        pos = b
        if seg["t"] == "link":
            p.links.append(seg["url"])
        elif seg["t"] == "mention" and seg["handle"] not in p.mentions:
            p.mentions.append(seg["handle"])
        elif seg["t"] == "tag" and seg["tag"] not in p.hashtags:
            p.hashtags.append(seg["tag"])
    if pos < len(text):
        p.segments.append({"t": "text", "v": text[pos:]})

    if len(p.hashtags) > MAX_HASHTAGS:
        raise TextError("too_many_hashtags", f"At most {MAX_HASHTAGS} hashtags.")
    if len(p.mentions) > MAX_MENTIONS:
        raise TextError("too_many_mentions", f"At most {MAX_MENTIONS} mentions.")
    if len(p.links) > MAX_LINKS:
        raise TextError("too_many_links", f"At most {MAX_LINKS} links.")
    return p


def search_terms(q: str) -> str:
    q = normalize(q, multiline=False)
    if len(q) > 100:
        q = q[:100]
    return q


def escape_like(s: str) -> str:
    return s.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_")
