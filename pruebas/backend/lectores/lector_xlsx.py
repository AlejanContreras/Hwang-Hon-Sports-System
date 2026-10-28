# Lee un .xlsx solo con la libreria estandar (zipfile + ElementTree) y lo
# imprime como JSON. Valida de paso: CRC de cada entrada (testzip) y que todo
# el XML este bien formado (ElementTree lanza excepcion si no).
# Uso: python -I lector_xlsx.py archivo.xlsx
import json
import re
import sys
import zipfile
import xml.etree.ElementTree as ET

M = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
R = '{http://schemas.openxmlformats.org/officeDocument/2006/relationships}'

z = zipfile.ZipFile(sys.argv[1])
salida = {'crc_malo': z.testzip(), 'entradas': z.namelist(), 'formulas': 0, 'hojas': {}, 'orden': [], 'quote_prefix': []}

for nombre in z.namelist():
    if nombre.endswith('.xml') or nombre.endswith('.rels'):
        ET.fromstring(z.read(nombre))

cadenas = []
raiz = ET.fromstring(z.read('xl/sharedStrings.xml'))
for si in raiz.findall(M + 'si'):
    cadenas.append(''.join(t.text or '' for t in si.iter(M + 't')))

estilos = ET.fromstring(z.read('xl/styles.xml'))
for i, xf in enumerate(estilos.find(M + 'cellXfs').findall(M + 'xf')):
    if xf.get('quotePrefix') == '1':
        salida['quote_prefix'].append(i)

libro = ET.fromstring(z.read('xl/workbook.xml'))
for i, hoja in enumerate(libro.find(M + 'sheets').findall(M + 'sheet')):
    nombre = hoja.get('name')
    salida['orden'].append(nombre)
    datos = ET.fromstring(z.read('xl/worksheets/sheet%d.xml' % i))
    celdas = {}
    filas = 0
    for fila in datos.iter(M + 'row'):
        filas = max(filas, int(fila.get('r')))
        for c in fila.findall(M + 'c'):
            if c.find(M + 'f') is not None:
                salida['formulas'] += 1
            v = c.find(M + 'v')
            valor = None
            if v is not None:
                valor = cadenas[int(v.text)] if c.get('t') == 's' else float(v.text)
            celdas[c.get('r')] = {'v': valor, 's': int(c.get('s', '0')), 't': c.get('t')}
    combinadas = [m.get('ref') for m in datos.iter(M + 'mergeCell')]
    salida['hojas'][nombre] = {'celdas': celdas, 'filas': filas, 'combinadas': combinadas}

sys.stdout.buffer.write(json.dumps(salida, ensure_ascii=False).encode("utf-8"))
