# Ecovacs – IP-Symcon Modul

Bindet Ecovacs-Saugroboter (Deebot) in IP-Symcon ein -- Anzeige und Steuerung. Ecovacs bietet keine offizielle API an -- dieses Modul nutzt die gleiche inoffizielle Anmeldung wie die Ecovacs-App bzw. die Home-Assistant-Integration (`deebot_client`). Sowohl Status-Abfragen als auch Steuerbefehle laufen über denselben synchronen REST-Endpunkt (`iot/devmanager.do`) -- keine eigene MQTT-Anbindung nötig.

## Installation

Modulverwaltung → + → URL eintragen:
```
https://github.com/mwilkens780/IPSymcon-Ecovacs
```

## Konfiguration

1. **Ecovacs-Konto-Instanz** anlegen, dort die Ecovacs-E-Mail-Adresse und das Passwort eintragen (dieselben Zugangsdaten wie in der Ecovacs-App).
2. Über den Button **"Verbindung testen / Geräte auflisten"** in der Konto-Instanz prüfen, ob die Anmeldung funktioniert -- bei Erfolg werden alle gefundenen Geräte mit `did` (Geräte-ID), `resource` und `class` (Geräteklasse) aufgelistet.
3. Für jeden Saugroboter eine eigene **Ecovacs-Saugroboter**-Instanz anlegen, dort die Konto-Instanz auswählen und `did`/`resource`/`class` aus Schritt 2 eintragen.

Jede Saugroboter-Instanz fragt ihren Status eigenständig über die gemeinsame Konto-Instanz ab (Login/Session wird dort einmalig verwaltet, nicht pro Roboter).

## Angezeigte Werte

- **Batterie** (%)
- **Lädt** (an der Basisstation)
- **Status**: Reinigt / Pausiert / Kehrt zur Basis zurück / Lädt / Bereit / Offline / Unbekannt
- **Saugstufe**: Leise / Normal / Stark / Max+ (nicht jedes Modell unterstützt Max+)

## Steuerung

Über die Kachel der Saugroboter-Instanz: Start/Fortsetzen (▶️, setzt bei pausierter Reinigung automatisch fort statt neu zu starten), Pause (⏸), Stopp (⏹), zur Basis schicken (🏠) sowie Saugstufe wählen. Nach jedem Steuerbefehl wird der Status kurz danach automatisch neu abgefragt.

## Geräteverifizierung (nur beim ersten Login nötig)

Ecovacs verlangt bei einem neuen/unbekannten Geräte-Login eine einmalige Bestätigung per E-Mail-Code (Fehlercode 1013 im IPS-Log, Instanzstatus "Geräteverifizierung ... erforderlich"). In diesem Fall in der Konto-Instanz das aufklappbare Feld "Geräteverifizierung" öffnen: zuerst "E-Mail-Code anfordern" klicken, den per E-Mail zugestellten Code eintragen und "Code bestätigen" klicken. Danach ist die virtuelle Geräte-ID dauerhaft bei Ecovacs bekannt -- der Schritt muss nicht wiederholt werden, auch nicht wenn die Sitzung später abläuft.
