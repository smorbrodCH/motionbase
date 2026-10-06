# MotionBase MCP-Server

Ein MCP-Server, mit dem Claude Kurse in MotionBase lesen und schreiben kann.
Er läuft in der App selbst (Streamable HTTP unter `/mcp`) und authentifiziert
über OAuth 2.1 — jeder Nutzer verbindet sich als er selbst und sieht
ausschließlich seine eigenen Kurse.

## Anbinden in claude.ai

> **Voraussetzung:** eine öffentlich erreichbare HTTPS-URL. `motionbase.ddev.site`
> ist nur lokal auflösbar; claude.ai kommt da nicht hin. Für einen Test genügt ein
> Tunnel (`ddev share`, cloudflared, ngrok), produktiv die echte Domain.

1. claude.ai → Settings → Connectors → **Add custom connector**
2. URL: `https://<deine-domain>/mcp`
3. Claude registriert sich selbst über Dynamic Client Registration, schickt dich
   auf den MotionBase-Login und fragt dann die Freigabe ab.

Es muss **kein** OAuth-Client von Hand angelegt werden — `/oauth/register` (RFC 7591)
erledigt das. Wenn `APP_URL` nicht auf die öffentliche Domain zeigt, stimmen die
Discovery-Dokumente nicht; notfalls `MCP_AUTHORIZATION_SERVER` setzen.

## Tools

| Tool | Zweck |
|---|---|
| `list-topics` | Eigene Kurse mit Kapitel-/Sektionszahl |
| `get-topic` | Gliederung eines Kurses |
| `get-section` | Eine Seite als Markdown, Rich-Blöcke mit vollen Daten |
| `list-block-types` | Welche Blöcke es gibt und wie sie entstehen |
| `list-media` | Bilder, Lottie- und HTML-Dateien der Medienbibliothek |
| `create-chapter` | Kapitel anlegen |
| `create-section` | Seite anlegen (Inhalt als Markdown) |
| `update-section` | Titel, Publish-Status oder **ganzen** Body ersetzen |
| `create-interactive` | Interaktive HTML-Grafik ablegen |
| `add-interactive-block` | Grafik einsetzen |
| `add-quiz-block` | Quiz einsetzen (eine richtige Antwort je Frage) |
| `add-alert-block` | Hinweiskasten einsetzen |
| `add-youtube-block` | Video einsetzen |
| `add-image-block` | Bild aus der Bibliothek einsetzen |
| `add-lottie-block` | Lottie aus der Bibliothek einsetzen |
| `remove-block` | Einzelnen Block entfernen — **jeden** Typ |
| `move-block` | Block verschieben |
| `delete-content` | Sektion, Kapitel oder Thema löschen (Titel als Bestätigung) |

Alle elf Blocktypen sind schreibbar: fünf über Markdown, sechs über eigene
Tools. Dateien werden weiterhin im Web-Editor hochgeladen — der Server platziert
sie, nimmt aber keine Uploads entgegen.

**Bearbeiten:** Markdown-Blöcke ändert man, indem man den Body mit
`update-section` neu schreibt. Die übrigen sechs ersetzt man: `remove-block`,
dann neu einsetzen. Einen einzelnen Block loszuwerden geht ausschließlich über
`remove-block` — `update-section` würde alle Rich-Blöcke mitreißen.

### Markdown statt Editor.js-JSON

Sektionen speichern Editor.js-Blöcke. Die Tools übersetzen hin und zurück
(`app/Mcp/Support/MarkdownBlocks.php`), damit Claude normales Markdown schreibt:
`##`/`###`/`####`, Absätze, `-` und `1.` Listen, ` ``` ` Codeblöcke, `**fett**`,
`*kursiv*`, `` `code` ``, `[Links](url)`.

**Nicht** über Markdown abbildbar sind interaktive Grafiken, Quizze, Bilder und
Alerts. Beim Lesen erscheinen sie als `> [...]`-Platzhalter und stehen zusätzlich
unter `rich_blocks`. `update-section` mit `markdown` ersetzt den **ganzen** Body
und wirft sie damit weg — das Tool meldet in `dropped_rich_blocks`, was es
entfernt hat. Vorher lesen, danach mit `add-interactive-block` neu setzen.

## Sicherheit

- Jedes Tool geht über die Kurse des handelnden Users (`ResolvesOwnedContent`).
  Fremde IDs verhalten sich exakt wie nicht existierende, damit die Tools nicht
  zum Durchprobieren von IDs taugen.
- `add-interactive-block` akzeptiert nur App-Pfade und `http(s)`-URLs — dieselbe
  Regel, die auch die Renderer durchsetzen.
- Hochgeladene Grafiken landen auf dem privaten Disk und werden nur über
  `/interactive/{id}` mit `Content-Security-Policy: sandbox allow-scripts`
  ausgeliefert (siehe `resources/interactives/README.md`).
- Die Signaturschlüssel liegen unter `storage/oauth-*.key` und sind über
  `/storage/*.key` gitignored. Auf einem neuen Server einmal
  `php artisan passport:keys` ausführen.
  Passport nimmt sie nur mit den Rechten 600/660 an, sonst antworten `/mcp`
  und `/oauth/authorize` mit 500 – der Deploy setzt die Rechte jedes Mal neu.

## Lokal testen (ohne claude.ai)

```bash
# Einmalig: Client für persönliche Tokens
ddev exec php artisan passport:client --personal --name="MotionBase MCP"

# Token für einen Nutzer erzeugen
ddev exec php artisan tinker --execute="echo App\Models\User::where('email','du@example.com')->first()->createToken('mcp',['mcp:use'])->accessToken;"

# Tools auflisten
curl -sk -X POST https://motionbase.ddev.site/mcp \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

Wichtig ist der `Accept`-Header. Ohne ihn hält Laravel den Request für einen
Browser-Aufruf und schickt einen 302 auf den Login statt 401 mit
`WWW-Authenticate` — der Header, über den ein MCP-Client den OAuth-Server findet.

`php artisan mcp:inspector motionbase` startet alternativ den MCP-Inspector.
