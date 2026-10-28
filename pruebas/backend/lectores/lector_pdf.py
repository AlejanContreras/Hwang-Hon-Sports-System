# Extrae el texto de un PDF de FPDF (fuentes estandar, streams con
# FlateDecode) con la libreria estandar: descomprime cada stream con zlib y
# junta las cadenas de los operadores Tj, decodificadas como cp1252.
# Imprime JSON: {inicio, fin, paginas, texto}.
# Uso: python -I lector_pdf.py archivo.pdf
import json
import re
import sys
import zlib

datos = open(sys.argv[1], 'rb').read()
texto = []
for m in re.finditer(rb'stream\r?\n(.*?)\r?\nendstream', datos, re.S):
    bloque = m.group(1)
    try:
        bloque = zlib.decompress(bloque)
    except zlib.error:
        pass
    for cadena in re.findall(rb'\((.*?)(?<!\\)\)\s*Tj', bloque, re.S):
        cadena = re.sub(rb'\\([()\\])', rb'\1', cadena)
        texto.append(cadena.decode('cp1252'))

sys.stdout.buffer.write(json.dumps({
    'inicio': datos[:5].decode('latin-1'),
    'fin': datos.rstrip()[-5:].decode('latin-1'),
    'paginas': len(re.findall(rb'/Type /Page\b', datos)),
    'texto': '\n'.join(texto),
}, ensure_ascii=False).encode('utf-8'))
