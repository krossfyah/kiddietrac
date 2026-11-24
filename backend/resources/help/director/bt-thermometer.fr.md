---
title: Thermomètre Bluetooth à la borne
category: Daily Operations
order: 80
---
# Thermomètre Bluetooth

Lisez la température d’un enfant directement depuis un thermomètre Bluetooth jumelé à la borne.

## Configuration

1. Jumelez le thermomètre à la tablette de la borne (configuration unique, via les paramètres Bluetooth du système).
2. Le navigateur Web de la borne (Chrome 90+, Safari 16.4+) demande l’autorisation Bluetooth la première fois qu’une température est nécessaire.

## À l’arrivée

1. Le parent ou le membre du personnel sélectionne l’enfant sur la borne.
2. Touchez le bouton **Prendre la température**.
3. Le navigateur affiche la boîte de dialogue de jumelage Web Bluetooth.
4. Choisissez le thermomètre jumelé.
5. La lecture s’affiche à l’écran et remplit automatiquement la fiche de la borne.

## Ce qui est consigné

Chaque lecture est enregistrée dans `check_events.temperature_c` avec :
- La température en °C
- La méthode : `bluetooth` / `manual` / `scanner`
- L’ID de l’événement d’arrivée associé (la température est ainsi rattachée à l’arrivée du jour)

## Alertes de fièvre

Si la lecture est **≥ 38 °C** :
- Une notification **fever_alert** est envoyée au personnel du centre
- La borne affiche le message « Veuillez communiquer avec le centre »
- La lecture est signalée dans le résumé quotidien du bien-être

## Prérequis

- HTTPS (l’API Web Bluetooth ne fonctionne pas en HTTP)
- Un thermomètre compatible (tout appareil exposant le service BLE standard Health Thermometer `00001809-0000-1000-8000-00805f9b34fb`)
- Un navigateur moderne prenant en charge l’API Web Bluetooth
- L’autorisation Bluetooth accordée

## Thermomètres compatibles

Fonctionnement vérifié : iHealth, A&D, Beurer FT 90, Withings Thermo. La plupart des thermomètres BLE grand public utilisant le service standard fonctionneront sans code personnalisé.

## Solution de rechange

Si le Bluetooth n’est pas disponible, la même fenêtre de la borne permet au personnel de saisir la température manuellement avec **Méthode = manual**.
