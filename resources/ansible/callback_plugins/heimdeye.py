# Callback d'HeimdEye: en acabar el playbook escriu a stdout un JSON amb el
# resultat de cada tasca per host i el resum (stats) de cada host.
from __future__ import annotations

import json

from ansible.plugins.callback import CallbackBase

DOCUMENTATION = '''
    name: heimdeye
    type: stdout
    short_description: Sortida JSON per a HeimdEye
    description: Resultat per host i per tasca, i el resum final, en un sol JSON.
'''


class CallbackModule(CallbackBase):
    CALLBACK_VERSION = 2.0
    CALLBACK_TYPE = 'stdout'
    CALLBACK_NAME = 'heimdeye'

    def __init__(self):
        super().__init__()
        self.events = []

    def _event(self, result, status):
        res = result._result
        msg = res.get('msg') or res.get('stderr') or res.get('stdout') or ''
        if not isinstance(msg, str):
            msg = json.dumps(msg, default=str)
        event = {
            'host': result._host.get_name(),
            'task': result._task.get_name(),
            'status': status,
            'msg': msg[:2000],
        }
        if res.get('diff'):
            event['diff'] = self._get_diff(res['diff'])[:4000]
        self.events.append(event)

    def v2_runner_on_ok(self, result):
        self._event(result, 'changed' if result._result.get('changed') else 'ok')

    def v2_runner_on_failed(self, result, ignore_errors=False):
        self._event(result, 'ignored' if ignore_errors else 'failed')

    def v2_runner_on_unreachable(self, result):
        self._event(result, 'unreachable')

    def v2_runner_on_skipped(self, result):
        self._event(result, 'skipped')

    def v2_playbook_on_stats(self, stats):
        summary = {host: stats.summarize(host) for host in sorted(stats.processed.keys())}
        self._display.display(json.dumps({'events': self.events, 'stats': summary}))
