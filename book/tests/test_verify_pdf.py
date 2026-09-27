import pytest

from build.verify_pdf import main


def write_text_pdf(path, x):
    """A one-page PDF with a known text position, including outside the MediaBox."""
    stream = f'BT /F1 12 Tf {x} 100 Td (outside) Tj ET'.encode()
    objects = [
        b'<< /Type /Catalog /Pages 2 0 R >>',
        b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] '
        b'/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        f'<< /Length {len(stream)} >>\nstream\n'.encode() + stream + b'\nendstream',
    ]
    pdf = bytearray(b'%PDF-1.4\n')
    offsets = []
    for number, obj in enumerate(objects, 1):
        offsets.append(len(pdf))
        pdf.extend(f'{number} 0 obj\n'.encode() + obj + b'\nendobj\n')
    xref = len(pdf)
    pdf.extend(b'xref\n0 6\n0000000000 65535 f \n')
    for offset in offsets:
        pdf.extend(f'{offset:010d} 00000 n \n'.encode())
    pdf.extend(f'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n'.encode())
    path.write_bytes(pdf)


@pytest.mark.parametrize(('x', 'result'), [(20, 0), (190, 1), (210, 1)])
def test_detects_partial_and_wholly_off_page_text(tmp_path, x, result):
    path = tmp_path / 'positioned-text.pdf'
    write_text_pdf(path, x)
    assert main([str(path)]) == result
