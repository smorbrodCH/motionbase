# Interaktive Elemente

Sie entstehen auf **eifach.online**, nicht in MotionBase. Dort gibt es, was beim Arbeiten zählt: Versionen,
Dateien bis 500 MB über die Oberfläche, das Design der Schule, Mitbearbeitende und eine Datenbank, wenn eine
Seite Eingaben sammelt.

MotionBase bettet sie nur ein. Im Editor «Interaktives Element» die Adresse der Seite einfügen, zum Beispiel
`https://bzz.eifach.online/physik-pendel/`. In Moodle erscheint sie im Buch, auf der Textseite oder in der
Aufgabe genauso.

Zwei Dinge regelt das Einbetten:

- **Herkunft.** Eine fremde Seite behält ihre eigene (`allow-same-origin`), sonst käme sie nicht an ihre eigene
  Datenbank und ihren Speicher. Früher hochgeladene Grafiken liegen auf unserer Adresse und laufen weiterhin
  abgeschottet – dort wäre das ein Loch.
- **Höhe.** Eine Seite meldet sie selbst (`eifach:resize`, bei älteren Grafiken `motionbase:resize`), der Rahmen
  stellt sich darauf ein. Ohne Meldung gilt die Höhe, die im Block steht.

## Bestehende Grafiken umziehen

```
# 1. Auf dem MotionBase-Server auspacken (74 Grafiken, rund 6 MB)
php artisan motionbase:interactives-export --owner=DEINE@MAIL.CH
# → storage/app/private/interactives-export/{html,dateien,auftrag.json,mapping.json}

# 2. Auf den eifach-Server kopieren und dort importieren
scp -r interactives-export eifach@SERVER:~/
pnpm exec esbuild scripts/import-pages.ts --bundle --platform=node --target=node22 --format=esm \
  --banner:js="import { createRequire } from 'node:module'; const require = createRequire(import.meta.url);" \
  --outfile=import-pages.mjs
set -a; . /srv/eifach/shared/.env; set +a; node import-pages.mjs ~/interactives-export/auftrag.json

# 3. Zurück auf dem MotionBase-Server: Lektionen auf die neuen Adressen umhängen
php artisan motionbase:interactives-relink interactives-export/mapping.json --dry-run
php artisan motionbase:interactives-relink interactives-export/mapping.json
```

Das Import-Skript überspringt Adressen, die es schon gibt, und überschreibt nie etwas. Vor Schritt 3 lohnt ein
Blick in die Liste der Seiten: Was dort fehlt, bleibt in MotionBase und wird nicht umgehängt.

Jede exportierte Seite trägt das Design der Schule im Kopf (`/_eifach/design/design.css`); dessen Grundstil
steht in `:where()` und überschreibt nichts, was die Grafik selbst setzt.
