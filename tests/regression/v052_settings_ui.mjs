import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const php = process.argv[2];
if (!php) throw new Error('Usage: node v052_settings_ui.mjs <php.exe>');
const checks = [];
const check = (name, pass) => checks.push({ name, pass: Boolean(pass) });
const scriptPath = path.join(root, 'assets/js/admin-settings.js');
check('settings script exists', fs.existsSync(scriptPath));
if (fs.existsSync(scriptPath)) {
    // A finite DOM boundary built from the real PHP template's two controls/fieldsets.
    // It exercises event handling and disabled submission fields; it is not a browser.
    const rendered = spawnSync(php, [path.join(root, 'tests/regression/v052_settings_ui.php'), '--fixture', 'payment', 'separate'], { encoding: 'utf8' });
    if (rendered.status !== 0 || rendered.stderr !== '') throw new Error('PHP fixture failed: ' + rendered.stderr);
    const attrs = (tag) => Object.fromEntries([...tag.matchAll(/([\w-]+)="([^"]*)"/g)].map((m) => [m[1], m[2]]));
    const fieldsets = new Map([...rendered.stdout.matchAll(/<fieldset\b([^>]*)>([\s\S]*?)<\/fieldset>/g)].map((m) => {
        const attributes = attrs(m[1]);
        const controls = [...m[2].matchAll(/<input\b([^>]*)>/g)].map((input) => ({ ...attrs(input[1]), disabled: false, checked: /\bchecked=/.test(input[1]) }));
        return [attributes.id, { hidden: false, controls, querySelectorAll: () => controls }];
    }));
    const selects = [...rendered.stdout.matchAll(/<select\b([^>]*)>([\s\S]*?)<\/select>/g)]
        .filter((m) => /\bdata-ys-ecpay-logistics-source(?:\s|=|$)/.test(m[1]))
        .map((m) => {
            const attributes = attrs(m[1]);
            const selected = [...m[2].matchAll(/<option\b([^>]*)>/g)].find((option) => /\bselected=/.test(option[1]));
            return { attributes, value: attrs(selected[1]).value, dataset: {}, listeners: {}, getAttribute: (key) => attributes[key], addEventListener(type, callback) { this.listeners[type] = callback; } };
        });
    const providerTag = rendered.stdout.match(/<input\b([^>]*\bid="ys-ec-ecpay-enabled"[^>]*)>/);
    const panelTag = rendered.stdout.match(/<div\b([^>]*\bid="ys-ec-ecpay-provider-settings"[^>]*)>/);
    const provider = providerTag ? {
        checked: /\bchecked=/.test(providerTag[1]),
        listeners: {}, attributes: {},
        addEventListener(type, callback) { this.listeners[type] = callback; },
        setAttribute(key, value) { this.attributes[key] = String(value); },
    } : null;
    const providerPanel = panelTag ? { hidden: /\bhidden(?:\s|=|$)/.test(panelTag[1]) } : null;
    check('real HTML exposes exactly two independent groups', selects.length === 2 && fieldsets.size === 2);
    check('real HTML exposes provider visibility controls', provider !== null && providerPanel !== null);
    if (selects.length === 2 && fieldsets.size === 2 && provider && providerPanel) {
        const document = {
            readyState: 'complete',
            querySelectorAll: () => selects,
            getElementById: (id) => id === 'ys-ec-ecpay-enabled' ? provider : (id === 'ys-ec-ecpay-provider-settings' ? providerPanel : fieldsets.get(id)),
        };
        vm.runInNewContext(fs.readFileSync(scriptPath, 'utf8'), { document }, { filename: scriptPath });
        const [b2c, c2c] = selects;
        const b2cFields = fieldsets.get(b2c.attributes['aria-controls']);
        const c2cFields = fieldsets.get(c2c.attributes['aria-controls']);
        check('shared payment group initially hidden and all five controls disabled', b2cFields.hidden && b2cFields.controls.length === 5 && b2cFields.controls.every((control) => control.disabled));
        check('separate group initially visible and enabled', !c2cFields.hidden && c2cFields.controls.every((control) => !control.disabled));
        b2c.value = 'separate'; b2c.listeners.change();
        check('selecting separate reveals and enables its fields', !b2cFields.hidden && b2cFields.controls.every((control) => !control.disabled));
        b2cFields.controls[1].value = 'edited-fixture-merchant';
        b2cFields.controls[4].checked = true;
        const values = b2cFields.controls.map((control) => [control.value, control.checked]);
        for (const mode of ['disabled', 'payment', 'legacy']) {
            b2c.value = mode; b2c.listeners.change();
            check(mode + ' hides and disables only its own controls', b2cFields.hidden && b2cFields.controls.every((control) => control.disabled) && !c2cFields.hidden && c2cFields.controls.every((control) => !control.disabled));
        }
        b2c.value = 'separate'; b2c.listeners.change();
        check('mode switches retain entered values and clear checkbox state', JSON.stringify(values) === JSON.stringify(b2cFields.controls.map((control) => [control.value, control.checked])));
        c2c.value = 'disabled'; c2c.listeners.change();
        check('second selector changes independently', c2cFields.hidden && c2cFields.controls.every((control) => control.disabled) && !b2cFields.hidden && b2cFields.controls.every((control) => !control.disabled));
        check('enabled provider content is initially visible', !providerPanel.hidden && provider.attributes['aria-expanded'] === 'true');
        provider.checked = false; provider.listeners.change();
        check('turning provider off hides the settings without changing source controls', providerPanel.hidden && !b2cFields.hidden && b2cFields.controls.every((control) => !control.disabled));
        provider.checked = true; provider.listeners.change();
        check('turning provider on reveals the settings again', !providerPanel.hidden && provider.attributes['aria-expanded'] === 'true');
    }
}
const fail = checks.filter((entry) => !entry.pass).length;
console.log(JSON.stringify({ scope: 'real PHP HTML + real JS, finite DOM/Node VM; no browser', pass: checks.length - fail, fail, checks }, null, 2));
process.exitCode = fail ? 1 : 0;
