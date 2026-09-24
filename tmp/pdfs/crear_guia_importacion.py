from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import mm
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, PageBreak, KeepTogether

OUTPUT = 'output/pdf/guia-carga-excel-importacion-historica.pdf'
NAVY = colors.HexColor('#091426')
TEAL = colors.HexColor('#0f766e')
BLUE = colors.HexColor('#2563eb')
LIGHT = colors.HexColor('#f1f5f9')
MID = colors.HexColor('#cbd5e1')
DARK = colors.HexColor('#1e293b')

styles = getSampleStyleSheet()
styles.add(ParagraphStyle(name='CoverTitle', parent=styles['Title'], fontName='Helvetica-Bold', fontSize=26, leading=31, textColor=colors.white, alignment=TA_LEFT, spaceAfter=8))
styles.add(ParagraphStyle(name='CoverSub', parent=styles['Normal'], fontSize=12, leading=18, textColor=colors.HexColor('#cbd5e1')))
styles.add(ParagraphStyle(name='H1x', parent=styles['Heading1'], fontName='Helvetica-Bold', fontSize=17, leading=21, textColor=NAVY, spaceBefore=8, spaceAfter=8))
styles.add(ParagraphStyle(name='H2x', parent=styles['Heading2'], fontName='Helvetica-Bold', fontSize=12, leading=15, textColor=TEAL, spaceBefore=7, spaceAfter=5))
styles.add(ParagraphStyle(name='Bodyx', parent=styles['BodyText'], fontSize=9.5, leading=14, textColor=DARK, spaceAfter=5))
styles.add(ParagraphStyle(name='Smallx', parent=styles['BodyText'], fontSize=8, leading=11, textColor=DARK))
styles.add(ParagraphStyle(name='Cell', parent=styles['BodyText'], fontSize=8, leading=10, textColor=DARK))
styles.add(ParagraphStyle(name='CellWhite', parent=styles['BodyText'], fontSize=8, leading=10, textColor=colors.white, fontName='Helvetica-Bold'))
styles.add(ParagraphStyle(name='Callout', parent=styles['BodyText'], fontSize=9.5, leading=14, textColor=NAVY, backColor=colors.HexColor('#e0f2fe'), borderColor=colors.HexColor('#7dd3fc'), borderWidth=0.6, borderPadding=8, spaceBefore=5, spaceAfter=8))

def P(text, style='Bodyx'):
    return Paragraph(text, styles[style])

def footer(canvas, doc):
    canvas.saveState()
    canvas.setStrokeColor(MID)
    canvas.line(18*mm, 14*mm, 192*mm, 14*mm)
    canvas.setFont('Helvetica', 7.5)
    canvas.setFillColor(colors.HexColor('#64748b'))
    canvas.drawString(18*mm, 9*mm, 'SLAD · Guía de importación histórica')
    canvas.drawRightString(192*mm, 9*mm, f'Página {doc.page}')
    canvas.restoreState()

def table(data, widths, header=True):
    t = Table(data, colWidths=widths, repeatRows=1 if header else 0, hAlign='LEFT')
    commands = [
        ('VALIGN', (0,0), (-1,-1), 'TOP'),
        ('GRID', (0,0), (-1,-1), 0.35, MID),
        ('LEFTPADDING', (0,0), (-1,-1), 6), ('RIGHTPADDING', (0,0), (-1,-1), 6),
        ('TOPPADDING', (0,0), (-1,-1), 5), ('BOTTOMPADDING', (0,0), (-1,-1), 5),
    ]
    if header:
        commands += [('BACKGROUND', (0,0), (-1,0), NAVY), ('TEXTCOLOR', (0,0), (-1,0), colors.white)]
        start = 1
    else:
        start = 0
    for row in range(start, len(data)):
        if (row - start) % 2 == 0:
            commands.append(('BACKGROUND', (0,row), (-1,row), LIGHT))
    t.setStyle(TableStyle(commands))
    return t

story = []

# Cover
cover = Table([[P('SLAD', 'CoverTitle'), P('Sistema Logístico de Administración de Derecho', 'CoverSub')]], colWidths=[42*mm, 118*mm])
cover.setStyle(TableStyle([
    ('BACKGROUND', (0,0), (-1,-1), NAVY), ('VALIGN', (0,0), (-1,-1), 'MIDDLE'),
    ('LEFTPADDING', (0,0), (-1,-1), 13), ('RIGHTPADDING', (0,0), (-1,-1), 13),
    ('TOPPADDING', (0,0), (-1,-1), 18), ('BOTTOMPADDING', (0,0), (-1,-1), 18),
]))
story += [Spacer(1, 30*mm), cover, Spacer(1, 18*mm), P('Guía para cargar un archivo Excel histórico', 'H1x'), P('Instrucciones para preparar, analizar y confirmar la importación de causas, actuaciones y movimientos financieros.', 'Bodyx'), Spacer(1, 6*mm)]
story.append(P('<b>Importante:</b> seleccionar un archivo solo inicia el análisis. La información no se guarda como causa hasta que el usuario revise la previsualización y confirme explícitamente.', 'Callout'))
story += [Spacer(1, 8*mm), P('Versión del documento: 1.0 · Plataforma Chile · Montos en pesos chilenos (CLP)', 'Smallx')]
story.append(PageBreak())

story += [P('1. Antes de comenzar', 'H1x'), P('El archivo debe ser un libro Excel válido con extensión <b>.xlsx</b> o <b>.xls</b> y un tamaño máximo de 10 MB. El importador busca primero la hoja <b>B.D. GENERAL</b>.', 'Bodyx'), P('No es necesario eliminar las otras hojas, pero solo se procesa la hoja principal; estadísticas, gráficos, totales y fórmulas derivadas no se importan.', 'Bodyx'), P('El usuario debe tener permiso para ver importaciones. Para confirmar la carga necesita además el permiso <b>importaciones.ejecutar</b>.', 'Bodyx'), P('2. Encabezados recomendados', 'H1x'), P('El orden de las columnas no es obligatorio: SLAD identifica los campos por su encabezado. Para evitar advertencias, usa estos nombres y este orden:', 'Bodyx')]
headers = [
    ['Orden', 'Encabezado', 'Uso'],
    ['1', 'NOMBRE CAUSA', 'Nombre del expediente'], ['2', 'FECHA CAUSA', 'Fecha de la causa'], ['3', 'FECHA DE INGRESO', 'Fecha de ingreso'], ['4', 'CIUDAD', 'Ciudad del juzgado'], ['5', 'NÚMERO DE JUZGADO', 'Nombre del juzgado (encabezado histórico)'], ['6', 'MATERIA', 'Materia jurídica'], ['7', 'SUB MATERIA', 'Submateria dentro de la materia'], ['8', 'ESTADO PROCESAL', 'Estado procesal'], ['9', 'DIRECCION', 'Dirección municipal'], ['10', 'A CARGO', 'Código del usuario responsable'], ['11', 'NRO CAUSA', 'Rol o número como texto'], ['12', 'ACCION', 'Acción'], ['13', 'ESTADO', 'Estado de la causa'], ['14', 'MONTO DEMANDADO', 'Monto en CLP'], ['15', 'OBSERVACION CAUSA', 'Actuaciones históricas'], ['16', 'OBSERVACION IMPORTANTE', 'Texto jurídico preservado'], ['17', 'INGRESO', 'Ingreso financiero'], ['18', 'EGRESO', 'Egreso financiero'], ['19', 'CON/SIN COTIZACIONES', 'CON o SIN'], ['20', 'FUNCIONARIO/A USUARIO/A', 'Se ignora; no crea usuarios'],
]
story.append(table([[P(x, 'CellWhite') for x in headers[0]]] + [[P(x, 'Cell') for x in row] for row in headers[1:]], [13*mm, 55*mm, 92*mm]))
story += [Spacer(1, 5*mm), P('<b>Obligatorios:</b> NOMBRE CAUSA, MATERIA y NRO CAUSA. El encabezado histórico escrito como “NOMBRE CASUSA” también es reconocido. Para el juzgado, usa el nombre exacto del catálogo; ya no se utilizan número ni tipo.', 'Callout'), P('El correlativo “N°” de Excel no se utiliza. Las columnas adicionales se pueden dejar: serán ignoradas.', 'Bodyx')]
story.append(PageBreak())

story += [P('3. Valores y formatos aceptados', 'H1x'), P('Fechas', 'H2x'), P('Se aceptan fechas como <b>20.08.2026</b>, <b>20/08/2026</b>, <b>20-08-2026</b> y fechas serializadas de Excel. No se inventa la fecha actual ni la fecha de la causa cuando el valor no existe.', 'Bodyx'), P('Montos CLP', 'H2x'), P('Usa valores como <b>$1.500.000</b>, <b>1.500.000</b> o <b>1500000</b>. Se guardan como números sin símbolo ni separadores. Los montos con decimales no enteros generan error porque SLAD trabaja con pesos chilenos enteros.', 'Bodyx'), P('Responsables', 'H2x'), P('En A CARGO escribe el código existente del usuario, por ejemplo <b>FV</b> o <b>LR</b>. El importador busca el código en <b>users.codigo</b>; nunca crea usuarios, RUT, correos ni contraseñas.', 'Bodyx'), P('Catálogos', 'H2x'), P('Materias y submaterias desconocidas se muestran como nuevas y pueden crearse al confirmar. Ciudades, juzgados, estados, acciones y direcciones desconocidos quedan nulos con una advertencia para revisión.', 'Bodyx'), P('Actuaciones', 'H2x'), P('En OBSERVACION CAUSA puedes incluir varias actuaciones. SLAD reconoce patrones como <b>11.12.25 LIQUIDACION COSTAS</b> o <b>12/12/2025 NO HA LUGAR</b>. Si el texto no se puede separar con seguridad, se conserva completo como una actuación sin fecha.', 'Bodyx'), P('Finanzas', 'H2x'), P('INGRESO y EGRESO crean movimientos financieros independientes. Si el Excel no trae fecha para el movimiento, la fecha queda nula: nunca se usa la fecha de la causa ni la fecha actual.', 'Bodyx')]
story.append(P('<b>No agregues fórmulas de totales esperando que se importen:</b> SLAD calcula sus estadísticas directamente desde la base de datos.', 'Callout'))
story += [P('4. Flujo de importación', 'H1x')]
flow = [['Paso', 'Qué ocurre'], ['1. Seleccionar', 'Elige el archivo .xlsx o .xls en Administración → Importación histórica.'], ['2. Analizar', 'SLAD valida extensión, MIME, tamaño, hoja y encabezados; luego normaliza los datos.'], ['3. Revisar', 'Consulta resumen, advertencias, errores, responsables, catálogos y posibles duplicados.'], ['4. Previsualizar', 'Revisa hasta 100 filas con su resultado: VÁLIDO, ADVERTENCIA, ERROR o POSIBLE DUPLICADO.'], ['5. Confirmar', 'Marca la casilla de confirmación y pulsa Importar datos. Sin esta acción no se crean causas.'], ['6. Resultado', 'El sistema muestra filas creadas/omitidas, actuaciones, movimientos, responsables y errores.']]
story.append(table([[P(x, 'CellWhite') for x in flow[0]]] + [[P(x, 'Cell') for x in row] for row in flow[1:]], [32*mm, 128*mm]))
story.append(PageBreak())

story += [P('5. Cómo leer la previsualización', 'H1x'), P('Cada fila muestra el número de causa, nombre, materia, submateria, responsable, estado, monto, actuaciones, ingresos, egresos y resultado.', 'Bodyx')]
results = [['Resultado', 'Significado'], ['VÁLIDO', 'Puede importarse sin advertencias.'], ['ADVERTENCIA', 'Puede importarse, pero requiere revisión. Ejemplos: responsable desconocido, juzgado no inequívoco o catálogo opcional faltante.'], ['ERROR', 'No se importa hasta corregir el dato. Ejemplos: materia faltante, fecha inválida o monto ilegible.'], ['POSIBLE DUPLICADO', 'Existe una causa similar. Se omite para evitar duplicar silenciosamente.'], ['DUPLICADO', 'Coincide con una causa existente por la combinación de número, nombre, materia, juzgado y ciudad. Se omite.']]
story.append(table([[P(x, 'CellWhite') for x in results[0]]] + [[P(x, 'Cell') for x in row] for row in results[1:]], [38*mm, 122*mm]))
story += [P('6. Recomendaciones antes de confirmar', 'H1x'), P('• Corrige errores de formato directamente en el Excel y vuelve a analizar.<br/>• Revisa responsables no encontrados y crea/asigna previamente los usuarios con su código correcto.<br/>• Regulariza ciudades y juzgados ambiguos desde Catálogos antes de volver a cargar.<br/>• Verifica montos e ingresos/egresos en CLP.<br/>• No confirmes una fila marcada como posible duplicado sin resolver primero la causa existente.', 'Bodyx'), P('7. Historial y errores', 'H1x'), P('Cada análisis queda registrado con archivo, SHA-256, usuario ejecutor, estado, cantidades y resumen. Las incidencias se pueden descargar como CSV desde el historial para corregirlas administrativamente.', 'Bodyx'), P('Si el mismo archivo ya fue importado, SLAD lo identifica por su hash y bloquea una segunda ejecución automática.', 'Bodyx')]
story.append(P('<b>Datos que normalmente requieren revisión administrativa:</b> códigos A CARGO que no existan en users.codigo, juzgados ambiguos, ciudades no catalogadas, estados desconocidos y posibles duplicados.', 'Callout'))
story += [Spacer(1, 8*mm), P('Soporte operativo', 'H2x'), P('Ruta del módulo: <b>Administración → Importación histórica</b><br/>Formato de archivo: Excel .xlsx o .xls<br/>Tamaño máximo: 10 MB<br/>Moneda: pesos chilenos (CLP)', 'Bodyx')]

doc = SimpleDocTemplate(OUTPUT, pagesize=A4, rightMargin=18*mm, leftMargin=18*mm, topMargin=16*mm, bottomMargin=20*mm, title='Guía de carga Excel - SLAD', author='SLAD')
doc.build(story, onFirstPage=footer, onLaterPages=footer)
print(OUTPUT)
