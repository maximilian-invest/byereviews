# Template patches for the customer dashboard that the design doesn't cover yet: cancel order + notices.
# Executed by tools/build_site.py with `s` (template string) and `rep()` in scope.

IN = 'border:1px solid #D2D2D2;background:#F7F7F7;border-radius:16px;padding:14px 16px;font-size:16px;outline:none;width:100%'
LB = 'font-size:14px;font-weight:500'
CARD = 'background:#FFFFFF;border-radius:24px;padding:clamp(18px,2.4vw,26px);display:flex;flex-direction:column;gap:12px'
BTN = 'align-self:flex-start;height:48px;border:0;cursor:pointer;background:#151515;color:#fff;border-radius:14px;padding:0 20px;font-size:15px;font-weight:500'
BTN2 = 'align-self:flex-start;height:44px;border:1px solid #D2D2D2;cursor:pointer;background:#FFFFFF;color:#151515;border-radius:14px;padding:0 16px;font-size:14px;font-weight:500'
BIG = 'height:56px;border:0;cursor:pointer;background:#151515;color:#fff;border-radius:18px;font-size:16px;font-weight:500'


def field(label, value, handler, typ='text', auto=''):
    ac = f'autocomplete="{auto}" ' if auto else ''
    return (f'<div style="display:flex;flex-direction:column;gap:6px"><label style="{LB}">{label}</label>'
            f'<input type="{typ}" value="{{{{ {value} }}}}" onChange="{{{{ {handler} }}}}" {ac}style="{IN}"></div>')


def grid(minw, *items):
    return f'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min({minw}px,100%),1fr));gap:12px">' + ''.join(items) + '</div>'


def sc_if(cond, inner, hint='false'):
    return f'<sc-if value="{{{{ {cond} }}}}" hint-placeholder-val="{{{{ {hint} }}}}">{inner}</sc-if>'


greet = '<span style="color:#9E9E9E">{{ acc.h1a }}</span> {{ acc.h1b }}</h1>'

# ---------- cancel order (customer) ----------
cancel_card = sc_if('pCanCancel',
    f'<div style="{CARD};border:1px solid #D2D2D2"><span style="font-size:18px;font-weight:500">Cancel your order</span>'
    '<span style="font-size:14px;color:#6B6B6B;line-height:1.5">We stop working on all reviews that are still open. Reviews that were already removed stay billable.</span>'
    + sc_if('cancelOpen',
            f'<textarea value="{{{{ cancelReason }}}}" onChange="{{{{ onCancelReason }}}}" rows="2" placeholder="Reason (optional)" style="{IN};resize:vertical"></textarea>'
            f'<div style="display:flex;gap:8px;flex-wrap:wrap"><button onClick="{{{{ confirmCancel }}}}" style="{BTN}">Cancel order</button><button onClick="{{{{ closeCancel }}}}" style="{BTN2}">Keep order</button></div>')
    + sc_if('cancelClosed', f'<button onClick="{{{{ openCancel }}}}" style="{BTN2}">Cancel order</button>', 'true')
    + '</div>')
rep('  <sc-if value="{{ pHasInvoice }}" hint-placeholder-val="{{ false }}">', cancel_card + '\n  <sc-if value="{{ pHasInvoice }}" hint-placeholder-val="{{ false }}">')
rep(greet, greet
    + sc_if('hasAccNotice', '<span style="font-size:14px;font-weight:500;background:#FFFFFF;border-radius:12px;padding:8px 12px;align-self:flex-start">{{ accNotice }}</span>'))
