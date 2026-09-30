# Template patches for the logged-in customer area (interim UI until the Account design arrives).
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


# logged in: the marketing nav disappears (logo only)
h0, h1 = s.index('<header '), s.index('</header>')
head = s[h0:h1].replace('value="{{ desktop }}"', 'value="{{ siteNavDesk }}"').replace('value="{{ mobile }}"', 'value="{{ siteNavMob }}"')
assert head.count('siteNavDesk') == 2 and head.count('siteNavMob') == 1
s = s[:h0] + head + s[h1:]

country_select = (f'<div style="display:flex;flex-direction:column;gap:6px"><label style="{LB}">Country</label>'
                  f'<select value="{{{{ acc.country }}}}" onChange="{{{{ acc.onCountry }}}}" style="{IN}">'
                  '<sc-for list="{{ countries }}" as="c" hint-placeholder-count="3"><option value="{{ c }}">{{ c }}</option></sc-for></select></div>')

settings = (
    sc_if('pTabSettings',
          '<div style="display:flex;flex-direction:column;gap:12px;max-width:760px">'
          # profile
          f'<div style="{CARD}"><span style="font-size:18px;font-weight:500">Profile</span>'
          + grid(240, field('Full name', 'acc.name', 'acc.onName', auto='name'), field('Company', 'acc.company', 'acc.onCompany', auto='organization'),
                 field('Phone', 'acc.phone', 'acc.onPhone', 'tel', 'tel'), field('Street', 'acc.street', 'acc.onStreet', auto='street-address'),
                 field('ZIP &amp; city', 'acc.city', 'acc.onCity'), country_select)
          + f'<button onClick="{{{{ acc.saveProfile }}}}" style="{BTN}">Save changes</button></div>'
          # email
          f'<div style="{CARD}"><span style="font-size:18px;font-weight:500">Email address</span><span style="font-size:15px">{{{{ acc.email }}}}</span>'
          + sc_if('acc.hasPending',
                  '<div style="background:#F4F4F4;border-radius:16px;padding:14px 16px;display:flex;flex-direction:column;gap:10px;font-size:14px;line-height:1.5;color:#333">'
                  '<span>Confirm your new address – we sent a link to <strong style="font-weight:500">{{ acc.pending }}</strong> (valid 24 h). Until then you log in with {{ acc.email }}.</span>'
                  f'<div style="display:flex;gap:8px;flex-wrap:wrap"><button onClick="{{{{ acc.resendEmail }}}}" style="{BTN2}">Resend link</button>'
                  f'<button onClick="{{{{ acc.cancelEmail }}}}" style="{BTN2}">Cancel change</button></div></div>')
          + sc_if('acc.showEmailForm',
                  grid(240, field('New email', 'acc.newEmail', 'acc.onNewEmail', 'email', 'email'), field('Current password', 'acc.emailPw', 'acc.onEmailPw', 'password', 'current-password'))
                  + f'<button onClick="{{{{ acc.saveEmail }}}}" style="{BTN}">Send confirmation link</button>')
          + sc_if('acc.showEmailBtn', f'<button onClick="{{{{ acc.openEmail }}}}" style="{BTN2}">Change email</button>', 'true')
          + '</div>'
          # password
          f'<div style="{CARD}"><span style="font-size:18px;font-weight:500">Password</span>'
          + grid(200, field('Current password', 'acc.pwCur', 'acc.onPwCur', 'password', 'current-password'), field('New password', 'acc.pwNew', 'acc.onPwNew', 'password', 'new-password'),
                 field('Repeat new password', 'acc.pwRep', 'acc.onPwRep', 'password', 'new-password'))
          + f'<span style="font-size:13px;color:#6B6B6B">At least 10 characters. Other devices are logged out after the change.</span><button onClick="{{{{ acc.savePw }}}}" style="{BTN}">Change password</button></div>'
          # sessions
          f'<div style="{CARD}"><span style="font-size:18px;font-weight:500">Sessions</span><span style="font-size:14px;color:#6B6B6B;line-height:1.5">Logged in on a shared or lost device? Log out everywhere except here.</span>'
          f'<button onClick="{{{{ acc.logoutAll }}}}" style="{BTN2}">Log out on all devices</button></div>'
          # delete
          f'<div style="{CARD};border:1px solid #D2D2D2"><span style="font-size:18px;font-weight:500">Delete account</span>'
          "<span style=\"font-size:14px;color:#6B6B6B;line-height:1.5\">Open orders and invoices must be kept for legal reasons; your personal data is deleted as soon as that's no longer required.</span>"
          + sc_if('acc.deletionRequested', '<span style="font-size:14px;font-weight:500">Deletion requested on {{ acc.deletionDate }}</span>')
          + sc_if('acc.showDelete', '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end"><div style="flex:1 1 220px">'
                  + field('Password', 'acc.delPw', 'acc.onDelPw', 'password', 'current-password') + f'</div><button onClick="{{{{ acc.requestDelete }}}}" style="{BTN2}">Request deletion</button></div>', 'true')
          + '</div>'
          + sc_if('acc.hasMsg', '<div style="position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:200;background:#151515;color:#fff;border-radius:16px;padding:14px 18px;font-size:14px;font-weight:500;box-shadow:0 10px 30px rgba(0,0,0,.25);max-width:calc(100vw - 32px)">{{ acc.msg }}</div>')
          + '</div>')
    + '\n')
rep('  <sc-if value="{{ pTabSupport }}" hint-placeholder-val="{{ false }}">', settings + '  <sc-if value="{{ pTabSupport }}" hint-placeholder-val="{{ false }}">')

# notice after the email confirmation link (?email=changed|invalid)
greet = "<span style=\"color:#9E9E9E\">Hi {{ pFirst }},</span> here's your status.</h1>"
rep(greet, greet + sc_if('hasAccNotice', '<span style="font-size:14px;font-weight:500;background:#FFFFFF;border-radius:12px;padding:8px 12px;align-self:flex-start">{{ accNotice }}</span>'))

# login page: "Set a new password" when opened from the reset email (/login/?reset=…)
l0 = s.index('<section data-screen-label="Login"')
c0 = s.index('  <div style="width:100%;max-width:440px;', l0)
c1 = s.index('\n  </div>\n</section>', c0) + len('\n  </div>')
login_card = s[c0:c1]
reset_card = ('  <div style="width:100%;max-width:440px;background:#FFFFFF;border-radius:28px;padding:clamp(24px,4vw,40px);display:flex;flex-direction:column;gap:16px">'
              '<h1 style="margin:0;font-size:clamp(32px,4vw,44px);font-weight:500;letter-spacing:-.04em;line-height:1"><span style="color:#9E9E9E">Set a</span> new password.</h1>'
              + sc_if('resetValid', '<p style="margin:0;font-size:15px;color:#6B6B6B;line-height:1.5">For {{ resetEmail }}. At least 10 characters.</p>'
                      + field('New password', 'resetPw', 'onResetPw', 'password', 'new-password') + field('Repeat new password', 'resetPw2', 'onResetPw2', 'password', 'new-password')
                      + sc_if('resetError', '<span style="font-size:14px;color:#6B6B6B">{{ resetErrorText }}</span>')
                      + f'<button onClick="{{{{ doResetConfirm }}}}" style="{BIG}">Save password</button>', 'true')
              + sc_if('resetExpired', "<p style=\"margin:0;font-size:15px;color:#6B6B6B;line-height:1.5\">This link has expired or was already used. Enter your email on the login page and we'll send you a new one.</p>"
                      + f'<button onClick="{{{{ leaveReset }}}}" style="{BIG}">Send a new link</button>')
              + '</div>')
s = s[:c0] + sc_if('loginMode', '\n' + login_card + '\n', 'true') + '\n' + sc_if('resetMode', '\n' + reset_card + '\n') + s[c1:]
