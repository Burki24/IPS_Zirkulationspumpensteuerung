# Zirkulationssteuerung

Dieses Modul steuert eine Warmwasser-Zirkulationspumpe abhängig von Bewegungsmeldern, um Energie zu sparen und gleichzeitig den Komfort zu erhalten.

## Inhaltsverzeichnis

1. Funktionsumfang
2. Voraussetzungen
3. Installation
4. Einrichtung
5. Statusvariablen
6. Visualisierung
7. PHP-Befehle

## 1. Funktionsumfang

* Steuerung einer Zirkulationspumpe über Bewegungsmelder
* Unterschiedliche Logik je Raum:
  * Badezimmer: direkte Aktivierung bei Bewegung
  * Küche: Aktivierung erst nach mehrfacher Bewegung innerhalb eines Zeitfensters
* Einstellbare Laufzeit der Pumpe
* Einstellbare Sperrzeit zur Vermeidung unnötiger Starts
* Zeitabhängige Laufzeiten (z. B. morgens/abends)
* Berücksichtigung von „noch warmem Wasser“ zur Laufzeitreduktion

### Energie- und Kostenberechnung

Das Modul ermittelt automatisch:

* Gesamtlaufzeit der Pumpe
* Tageslaufzeit
* Energieverbrauch (gesamt und täglich)
* Stromkosten (gesamt und täglich)
* Einsparungen gegenüber Dauerbetrieb

### Automatische Tageslogik

* Tageswerte werden automatisch um Mitternacht zurückgesetzt
* Der erste Tag berücksichtigt den Installationszeitpunkt (kein voller Tag notwendig)

## 2. Voraussetzungen

* Symcon ab Version 7.1
* Bewegungsmelder (z. B. Zigbee2MQTT)
* Schaltbarer Aktor (z. B. Steckdose)

## 3. Installation

* Über den Module Store installieren
* Oder manuell über Module Control: [IPS_Zirkulationspumpensteuerung](https://github.com/Burki24/IPS_Zirkulationspumpensteuerung)

## 4. Einrichtung

Instanz hinzufügen über:

### Instanzname

Zirkulationssteuerung

### Konfiguration

* **Zirkulationspumpe**: Schaltaktor der Pumpe
* **Bewegungsmelder Bad**: Startet die Pumpe direkt
* **Bewegungsmelder Küche**: Startet die Pumpe erst nach mehreren Triggern
* **Runtime**: Laufzeit der Pumpe in Sekunden
* **LockTime**: Mindestabstand zwischen Starts
* **TriggerCount**: Anzahl Bewegungen für Küchenstart
* **TriggerWindow**: Zeitfenster für Küchenlogik
* **PumpPower**: Leistung der Pumpe (Watt)
* **EnergyPrice**: Strompreis (€/kWh)

## 5. Statusvariablen

| Variable | Beschreibung        |
| -------- | ------------------- |
| Active   | Pumpe aktiv         |
| LastRun  | Letzte Aktivierung  |
| RunCount | Anzahl Starts       |

### Tageswerte

* DailyRuntime
* DailyEnergy
* DailySavings
* DailyCost

### Gesamtwerte

* TotalRuntime
* TotalRuntimeHours
* EstimatedEnergy
* SavedEnergy
* EnergyCost

### Kosten (pro Lauf)

* EnergyCostAccumulated
* DailyCostAccumulated

## 6. Visualisierung

Alle Variablen können im WebFront dargestellt werden.

Empfohlen:

* Diagramme für Energie und Kosten
* Anzeige der aktuellen Aktivität

## 7. PHP-Befehle

### ZPS_SwitchOn($id)

Startet die Zirkulationspumpe manuell.

Die Pumpe wird für die aktuell konfigurierte Laufzeit eingeschaltet,
unabhängig von Bewegungsmeldern oder Triggerlogik.

Parameter:

* `$id`: Instanz-ID der Zirkulationssteuerung

Beispiel:

```php
ZPS_SwitchOn(12345);
```

### ZPS_SwitchOff($id)

Schaltet die Zirkulationspumpe sofort aus.

Ein eventuell laufender Timer wird dabei ebenfalls beendet.

Parameter:

* `$id`: Instanz-ID der Zirkulationssteuerung

Beispiel:

```php
ZPS_SwitchOff(12345);
```
