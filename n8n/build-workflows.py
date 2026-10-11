# Genera los flujos importables de n8n a partir del código probado (n8n/*.js). Uso: python n8n/build-workflows.py
import json, pathlib, uuid
here = pathlib.Path(__file__).parent

def code_of(name):
    source = (here / name).read_text(encoding='utf-8')
    return '\n'.join(l for l in source.splitlines() if not l.startswith("if (typeof module !== 'undefined')"))

# Adaptador: n8n entrega el cuerpo crudo (opción Raw Body) y las variables de entorno; httpRequest sin lanzar en 4xx.
HTTP = """const http = async options => {
  const response = await this.helpers.httpRequest(Object.assign({}, options, { returnFullResponse: true, ignoreHttpStatusErrors: true, json: false }));
  const body = typeof response.body === 'string' ? response.body : JSON.stringify(response.body);
  if (response.statusCode >= 400) { const error = new Error('HTTP ' + response.statusCode); error.response = { body }; throw error; }
  return body;
};
const crypto = require('crypto');
const item = $input.first();
const headers = item.json.headers || {};
let raw;
try { raw = (await this.helpers.getBinaryDataBuffer(0, 'data')).toString('utf8'); }
catch (error) { raw = typeof item.json.body === 'string' ? item.json.body : JSON.stringify(item.json.body || {}); }
"""

def node(name, type_, version, position, parameters):
    return {'id': str(uuid.uuid5(uuid.NAMESPACE_URL, 'citaya/' + name)), 'name': name, 'type': type_, 'typeVersion': version, 'position': position, 'parameters': parameters}

def webhook_flow(flow_name, path, code_file, call):
    code = code_of(code_file) + '\n\n' + HTTP + call
    nodes = [
        node('Webhook', 'n8n-nodes-base.webhook', 2, [0, 0], {'httpMethod': 'POST', 'path': path, 'responseMode': 'responseNode', 'options': {'rawBody': True}}),
        node('Procesar', 'n8n-nodes-base.code', 2, [260, 0], {'mode': 'runOnceForAllItems', 'jsCode': code}),
        node('Responder', 'n8n-nodes-base.respondToWebhook', 1.1, [520, 0], {'respondWith': 'json', 'responseBody': '={{ { result: $json.result } }}', 'options': {'responseCode': '={{ $json.status }}'}}),
    ]
    connections = {'Webhook': {'main': [[{'node': 'Procesar', 'type': 'main', 'index': 0}]]}, 'Procesar': {'main': [[{'node': 'Responder', 'type': 'main', 'index': 0}]]}}
    return {'name': flow_name, 'nodes': nodes, 'connections': connections, 'settings': {'executionOrder': 'v1', 'saveDataSuccessExecution': 'none'}, 'active': False}

ENV = "{ CITAYA_URL: $env.CITAYA_URL, CITAYA_N8N_SECRET: $env.CITAYA_N8N_SECRET, CITAYA_WA_SECRET: $env.CITAYA_WA_SECRET, CITAYA_ADMIN_WHATSAPP: $env.CITAYA_ADMIN_WHATSAPP }"
bot = webhook_flow('CitaYa · Bot WhatsApp', 'citaya-whatsapp', 'whatsapp-bot.js',
    "const result = await citayaWhatsAppBot({ raw, signature: headers['x-hub-signature-256'], env: " + ENV + ", http, crypto });\nreturn [{ json: result }];")
(here / 'citaya-bot-whatsapp.json').write_text(json.dumps(bot, ensure_ascii=False, indent=2), encoding='utf-8')
if (here / 'citaya-events.js').exists():
    events = webhook_flow('CitaYa · Eventos', 'citaya-eventos', 'citaya-events.js',
        "const result = await citayaEvents({ raw, signature: headers['x-citaya-signature'], env: " + ENV + ", http, crypto });\nreturn [{ json: result }];")
    (here / 'citaya-eventos.json').write_text(json.dumps(events, ensure_ascii=False, indent=2), encoding='utf-8')
    recovery_code = code_of('citaya-events.js') + '\n\n' + HTTP.split('const item')[0] + "return [{ json: await citayaRecovery({ env: " + ENV + ", http, crypto }) }];"
    recovery = {'name': 'CitaYa · Recuperación de envíos', 'nodes': [
        node('Cada 5 minutos', 'n8n-nodes-base.scheduleTrigger', 1.2, [0, 0], {'rule': {'interval': [{'field': 'minutes', 'minutesInterval': 5}]}}),
        node('Reenviar pendientes', 'n8n-nodes-base.code', 2, [260, 0], {'mode': 'runOnceForAllItems', 'jsCode': recovery_code}),
    ], 'connections': {'Cada 5 minutos': {'main': [[{'node': 'Reenviar pendientes', 'type': 'main', 'index': 0}]]}},
        'settings': {'executionOrder': 'v1', 'saveDataSuccessExecution': 'none'}, 'active': False}
    (here / 'citaya-recuperacion.json').write_text(json.dumps(recovery, ensure_ascii=False, indent=2), encoding='utf-8')
if (here / 'citaya-followup.js').exists():
    followup_code = code_of('citaya-followup.js') + '\n\n' + HTTP.split('const item')[0] + "return [{ json: await citayaFollowUp({ env: " + ENV + ", http, crypto }) }];"
    followup = {'name': 'CitaYa · Seguimiento', 'nodes': [
        node('Cada hora', 'n8n-nodes-base.scheduleTrigger', 1.2, [0, 0], {'rule': {'interval': [{'field': 'hours', 'hoursInterval': 1, 'triggerAtMinute': 5}]}}),
        node('Revisar y avisar', 'n8n-nodes-base.code', 2, [260, 0], {'mode': 'runOnceForAllItems', 'jsCode': followup_code}),
    ], 'connections': {'Cada hora': {'main': [[{'node': 'Revisar y avisar', 'type': 'main', 'index': 0}]]}},
        'settings': {'executionOrder': 'v1', 'saveDataSuccessExecution': 'none'}, 'active': False}
    (here / 'citaya-seguimiento.json').write_text(json.dumps(followup, ensure_ascii=False, indent=2), encoding='utf-8')
if (here / 'citaya-errors.js').exists():
    # Flujo de errores: en cada flujo de CitaYa, Settings → Error workflow → «CitaYa · Errores». No necesita publicarse.
    errors_code = code_of('citaya-errors.js') + '\n\n' + HTTP.split('const item')[0] + "return [{ json: await citayaErrorAlert({ error: $input.first().json, env: " + ENV + ", http, crypto }) }];"
    errors = {'name': 'CitaYa · Errores', 'nodes': [
        node('Error en un flujo', 'n8n-nodes-base.errorTrigger', 1, [0, 0], {}),
        node('Avisar al responsable', 'n8n-nodes-base.code', 2, [260, 0], {'mode': 'runOnceForAllItems', 'jsCode': errors_code}),
    ], 'connections': {'Error en un flujo': {'main': [[{'node': 'Avisar al responsable', 'type': 'main', 'index': 0}]]}},
        'settings': {'executionOrder': 'v1'}, 'active': False}
    (here / 'citaya-errores.json').write_text(json.dumps(errors, ensure_ascii=False, indent=2), encoding='utf-8')
print('flujos generados')
