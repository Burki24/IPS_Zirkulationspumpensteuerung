# Zirkulationssteuerung
Dieses Modul steuert eine Warmwasser-Zirkulationspumpe abhängig von Bewegungsmeldern, um Energie zu sparen und gleichzeitig den Komfort zu erhalten.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in Symcon](#4-einrichten-der-instanzen-in-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

---

### 1. Funktionsumfang

* Steuerung einer Zirkulationspumpe über Bewegungsmelder
* Unterstützung für getrennte Logik:
  * Badezimmer → direkte Aktivierung
  * Küche → vorbereitete intelligente Triggerlogik
* Einstellbare Laufzeit der Pumpe
* Einstellbare Sperrzeit zur Vermeidung unnötiger Starts
* Nachtbetrieb mit reduzierter Laufzeit
* Zählung der Pumpenstarts
* Logging und Debug-Ausgaben über Symcon

---

### 2. Voraussetzungen

- Symcon ab Version 7.1
- Bewegungsmelder (z. B. über Zigbee2MQTT)
- Schaltbare Steckdose / Aktor für die Zirkulationspumpe

---

### 3. Software-Installation

* Über den Module Store das 'Zirkulationssteuerung'-Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen:
* https://github.com/Burki24/IPS_Zirkulationspumpensteuerung

---

### 4. Einrichten der Instanzen in Symcon

Unter 'Instanz hinzufügen' kann das 'Zirkulationssteuerung'-Modul mithilfe des Schnellfilters gefunden werden.  

Weitere Informationen:  
https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen

---

__Konfigurationsseite__:

Name                     | Beschreibung
------------------------ | ------------------
Zirkulationspumpe        | Variable des Schaltaktors (z. B. Steckdose)
Bewegungsmelder Bad      | Bewegungsmelder im Badezimmer
Bewegungsmelder Küche    | Bewegungsmelder in der Küche
Laufzeit                 | Laufzeit der Pumpe nach Aktivierung (Sekunden)
Sperrzeit                | Mindestzeit zwischen zwei Starts (Sekunden)
Trigger Anzahl (Küche)   | Anzahl Bewegungen für Aktivierung (Küche)
Zeitfenster (Sekunden)   | Zeitfenster für Küchen-Trigger
Nachtstart               | Beginn Nachtbetrieb (Stunde)
Nachtende                | Ende Nachtbetrieb (Stunde)
Nachtlaufzeit            | Reduzierte Laufzeit nachts (Sekunden)

---

### 5. Statusvariablen und Profile

Die Statusvariablen werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Name              | Typ     | Beschreibung
----------------- | ------- | ------------
Letzte Aktivierung | Integer | Zeitstempel der letzten Pumpenaktivierung
Anzahl Starts     | Integer | Anzahl der Pumpenstarts
Pumpe aktiv       | Boolean | Status der Zirkulationspumpe

---

#### Profile

Name             | Typ
---------------- | -------
~UnixTimestamp   | Integer
~Switch          | Boolean

---

### 6. Visualisierung

Das Modul stellt folgende Informationen im WebFront bereit:

* Anzeige, ob die Pumpe aktuell aktiv ist
* Anzeige der letzten Aktivierung
* Anzeige der Gesamtanzahl der Starts

Die Steuerung erfolgt vollständig automatisch im Hintergrund.

---

### 7. PHP-Befehlsreferenz

`void ZPS_SwitchOn(integer $InstanzID);`  
Startet die Zirkulationspumpe manuell.

Beispiel:
ZPS_SwitchOff(12345);

---

## Lizenz

MIT License
