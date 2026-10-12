"""Extracts the text of a public law PDF, page by page, for the Normative Library.

Usage: python tools/extract_pdf_text.py <input.pdf> <output.txt>
Pages are separated by a form feed so the parser can keep page references.
"""

import sys

from pypdf import PdfReader


def main(source: str, target: str) -> None:
    reader = PdfReader(source)
    pages = [page.extract_text() or "" for page in reader.pages]
    with open(target, "w", encoding="utf-8") as handle:
        handle.write("\f".join(pages))
    empty = sum(1 for page in pages if len(page.strip()) < 40)
    print(f"{len(pages)} páginas, {empty} sin texto, {sum(len(page) for page in pages)} caracteres")


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
