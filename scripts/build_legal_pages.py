#!/usr/bin/env python3

from __future__ import annotations

import argparse
import html
import re
from email import policy
from email.parser import BytesParser
from pathlib import Path
from zipfile import ZipFile

from lxml import etree
from lxml import html as lxml_html


W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"


def text_of(element: etree._Element) -> str:
    parts: list[str] = []
    for node in element.iter():
        if node.tag == W + "t" and node.text:
            parts.append(node.text)
        elif node.tag == W + "tab":
            parts.append("\t")
        elif node.tag == W + "br":
            parts.append("\n")
    return "".join(parts).strip()


def blocks_from_docx(path: Path) -> list[tuple[str, object]]:
    with ZipFile(path) as archive:
        root = etree.fromstring(archive.read("word/document.xml"))
    body = root.find(W + "body")
    if body is None:
        return []
    blocks: list[tuple[str, object]] = []
    for child in body:
        if child.tag == W + "p":
            text = text_of(child)
            if text:
                blocks.append(("p", text))
        elif child.tag == W + "tbl":
            rows: list[list[str]] = []
            for row in child.findall(W + "tr"):
                rows.append([text_of(cell) for cell in row.findall(W + "tc")])
            if rows:
                blocks.append(("table", rows))
    return blocks


def docx_to_html(path: Path, *, title: str) -> str:
    blocks = blocks_from_docx(path)
    output: list[str] = []
    list_open = False
    title_written = False

    def close_list() -> None:
        nonlocal list_open
        if list_open:
            output.append("</ul>")
            list_open = False

    for kind, value in blocks:
        if kind == "table":
            close_list()
            rows = value
            output.append('<div class="legal-table-wrap"><table>')
            for row in rows:
                output.append("<tr>")
                for index, cell in enumerate(row):
                    tag = "th" if index == 0 else "td"
                    scope = ' scope="row"' if tag == "th" else ""
                    lines = "<br />".join(html.escape(part) for part in str(cell).split("\n"))
                    output.append(f"<{tag}{scope}>{lines}</{tag}>")
                output.append("</tr>")
            output.append("</table></div>")
            continue

        text = str(value)
        if not title_written:
            close_list()
            output.append(f"<h1>{html.escape(text)}</h1>")
            title_written = True
        elif re.match(r"^\d+\.\s+", text):
            close_list()
            output.append(f"<h2>{html.escape(text)}</h2>")
        elif text.startswith("—"):
            if not list_open:
                output.append("<ul>")
                list_open = True
            output.append(f"<li>{html.escape(text.lstrip('— ').strip())}</li>")
        elif text.lower().startswith("редакция от"):
            close_list()
            output.append(f'<p class="legal-version">{html.escape(text)}</p>')
        else:
            close_list()
            output.append(f"<p>{html.escape(text).replace(chr(10), '<br />')}</p>")
    close_list()
    if not title_written:
        output.insert(0, f"<h1>{html.escape(title)}</h1>")
    return "\n".join(output)


def consent_html(path: Path) -> str:
    with ZipFile(path) as archive:
        message = BytesParser(policy=policy.default).parsebytes(archive.read("word/afchunk.mht"))
    html_part = next(
        part for part in message.walk() if part.get_content_type() == "text/html"
    )
    document = lxml_html.fromstring(html_part.get_content())
    body = document.find("body")
    if body is None:
        raise RuntimeError("Consent document does not contain an HTML body")
    for element in body.xpath(".//*"):
        element.attrib.pop("style", None)
        element.attrib.pop("align", None)
    for paragraph in body.xpath("./p"):
        if "".join(paragraph.itertext()).strip().lower().startswith("редакция от"):
            paragraph.set("class", "legal-version")
    for table in list(body.xpath(".//table")):
        parent = table.getparent()
        if parent is None:
            continue
        wrapper = etree.Element("div", {"class": "legal-table-wrap"})
        index = parent.index(table)
        parent.remove(table)
        wrapper.append(table)
        parent.insert(index, wrapper)
    return "\n".join(
        etree.tostring(child, encoding="unicode", method="html") for child in body
    )


def page(*, page_title: str, content: str, download_name: str) -> str:
    return f'''<!doctype html>
<html lang="ru">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex" />
    <meta name="theme-color" content="#9a6a48" />
    <title>{html.escape(page_title)} — Прожарка</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&amp;family=Manrope:wght@400;500;600;700&amp;family=Tenor+Sans&amp;display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../styles.css?v=12" />
    <link rel="stylesheet" href="../legal.css?v=1" />
  </head>
  <body class="legal-page" data-site-root="../">
    <a class="skip-link" href="#legal-document">К документу</a>
    <header class="legal-header">
      <a class="legal-brand" href="../">Про жарка</a>
      <a class="legal-back" href="../#legal">Вернуться на сайт</a>
    </header>
    <main class="legal-shell" id="legal-document">
      <article class="legal-document">
        {content}
        <p class="legal-download"><a href="../documents/{download_name}" download>Скачать исходный документ DOCX</a></p>
      </article>
    </main>
    <footer class="legal-footer">
      <nav aria-label="Юридические документы">
        <a href="../privacy/">Политика обработки данных</a>
        <a href="../consent/">Согласие на обработку данных</a>
        <a href="../offer/">Публичная оферта</a>
        <button type="button" data-cookie-settings>Настройки cookies</button>
      </nav>
      <p>© <span data-year></span> «Прожарка»</p>
    </footer>
    <script src="../script.js?v=9"></script>
  </body>
</html>
'''


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--privacy", type=Path, required=True)
    parser.add_argument("--consent", type=Path, required=True)
    parser.add_argument("--offer", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()

    documents = [
        (
            "privacy",
            "Политика обработки персональных данных",
            docx_to_html(args.privacy, title="Политика обработки персональных данных"),
            "privacy-policy.docx",
        ),
        (
            "consent",
            "Согласие на обработку персональных данных",
            consent_html(args.consent),
            "personal-data-consent.docx",
        ),
        (
            "offer",
            "Публичная оферта",
            docx_to_html(args.offer, title="Публичная оферта"),
            "public-offer.docx",
        ),
    ]
    for directory, title, content, download_name in documents:
        destination = args.output / directory
        destination.mkdir(parents=True, exist_ok=True)
        (destination / "index.html").write_text(
            page(page_title=title, content=content, download_name=download_name),
            encoding="utf-8",
        )


if __name__ == "__main__":
    main()
