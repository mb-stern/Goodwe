<?php

declare(strict_types=1);

class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Verknüpfe mit dem Modbus-Gateway
        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");

        // Eigenschaft für die ausgewählten Register
        $this->RegisterPropertyString("SelectedRegisters", "[]");

        // Timer zur zyklischen Abfrage
        $this->RegisterTimer("Poller", 0, 'Goodwe_RequestRead($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    
        // Lade die gespeicherten ausgewählten Register
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
    
        if (!is_array($selectedRegisters)) {
            $this->SendDebug("ApplyChanges", "SelectedRegisters ist ungültig: " . $this->ReadPropertyString("SelectedRegisters"), 0);
            $selectedRegisters = [];
        }
    
        $this->SendDebug("ApplyChanges: SelectedRegisters", json_encode($selectedRegisters), 0);
    
        foreach ($selectedRegisters as $registerAddress) {
            $register = $this->FindRegisterByAddress((int)$registerAddress);
            if ($register === null) {
                $this->SendDebug("ApplyChanges", "Kein Register gefunden für Adresse: $registerAddress", 0);
                continue;
            }
    
            $ident = "Addr" . $register['address'];
    
            // Prüfen, ob die Variable existiert, und falls nicht, erstellen
            if (!$this->GetIDForIdent($ident)) {
                $this->RegisterVariableFloat(
                    $ident,
                    $register['name'],
                    $this->GetVariableProfile($register['unit']),
                    0
                );
            }
        }
    }

    private function FindRegisterByAddress(int $address)
    {
        foreach ($this->GetRegisters() as $register) {
            if ($register['address'] === $address) {
                return $register;
            }
        }
        return null;
    }

    
    public function RequestRead()
    {
        $selectedAddresses = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        $this->SendDebug("RequestRead: SelectedAddresses", json_encode($selectedAddresses), 0);

        foreach ($selectedAddresses as $address) {
            $register = $this->GetRegisterByAddress($address);
            if (!$register) {
                $this->SendDebug("RequestRead", "Register mit Adresse $address nicht gefunden", 0);
                continue;
            }

            $value = $this->ReadRegister((int)$register['address'], $register['type'], (float)$register['scale']);
            $ident = "Addr" . $register['address'];

            if ($this->GetIDForIdent($ident)) {
                SetValue($this->GetIDForIdent($ident), $value);
            }
        }
    }

    public function GetConfigurationForm()
    {
        $registers = $this->GetRegisters();
        $selectedAddresses = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
    
        if (!is_array($selectedAddresses)) {
            $this->SendDebug("GetConfigurationForm", "SelectedAddresses ist ungültig: " . $this->ReadPropertyString("SelectedRegisters"), 0);
            $selectedAddresses = [];
        }
    
        $values = [];
        foreach ($registers as $register) {
            $values[] = [
                "address"  => $register['address'],
                "name"     => $register['name'],
                "type"     => $register['type'],
                "unit"     => $register['unit'],
                "scale"    => $register['scale'],
                "selected" => in_array($register['address'], $selectedAddresses)
            ];
        }
    
        $this->SendDebug("GetConfigurationForm", "Values: " . json_encode($values), 0);
    
        return json_encode([
            "elements" => [
                [
                    "type"  => "List",
                    "name"  => "SelectedRegisters",
                    "caption" => "Register",
                    "add"   => false,
                    "delete" => false,
                    "columns" => [
                        ["caption" => "Address", "name" => "address", "width" => "100px"],
                        ["caption" => "Name", "name" => "name", "width" => "200px"],
                        ["caption" => "Type", "name" => "type", "width" => "80px"],
                        ["caption" => "Unit", "name" => "unit", "width" => "80px"],
                        ["caption" => "Scale", "name" => "scale", "width" => "80px"],
                        ["caption" => "Selected", "name" => "selected", "width" => "80px", "edit" => ["type" => "CheckBox"]]
                    ],
                    "values" => $values
                ]
            ]
        ]);
    }
    
    private function ReadRegister(int $address, string $type, float $scale)
    {
        $quantity = ($type === "U32" || $type === "S32") ? 2 : 1;

        $response = $this->SendDataToParent(json_encode([
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address"  => $address,
            "Quantity" => $quantity
        ]));

        if ($response === false || strlen($response) < (2 * $quantity + 2)) {
            $this->SendDebug("Error", "Keine oder unvollständige Antwort für Register $address", 0);
            return 0;
        }

        $data = unpack("n*", substr($response, 2));
        $value = 0;

        switch ($type) {
            case "U16":
                $value = $data[1];
                break;
            case "S16":
                $value = ($data[1] & 0x8000) ? -((~$data[1] & 0xFFFF) + 1) : $data[1];
                break;
            case "U32":
                $value = ($data[1] << 16) | $data[2];
                break;
            case "S32":
                $combined = ($data[1] << 16) | $data[2];
                $value = ($data[1] & 0x8000) ? -((~$combined & 0xFFFFFFFF) + 1) : $combined;
                break;
            default:
                $this->SendDebug("Error", "Unbekannter Typ für Register $address: $type", 0);
        }

        return $value / $scale;
    }

    private function GetRegisterByAddress(int $address)
    {
        foreach ($this->GetRegisters() as $register) {
            if ($register['address'] === $address) {
                return $register;
            }
        }
        return null;
    }

    private function GetRegisters()
    {
        return [
            ["address" => 35103, "name" => "PV1 Voltage", "type" => "U16", "unit" => "V", "scale" => 10],
            ["address" => 35104, "name" => "PV1 Current", "type" => "U16", "unit" => "A", "scale" => 10],
            ["address" => 35191, "name" => "Total PV Energy", "type" => "U32", "unit" => "kWh", "scale" => 10],
            ["address" => 35107, "name" => "PV2 Voltage", "type" => "U16", "unit" => "V", "scale" => 10],
            ["address" => 36025, "name" => "Smartmeter Power", "type" => "S32", "unit" => "W", "scale" => 1]
        ];
    }

    private function GetVariableProfile(string $unit)
    {
        switch ($unit) {
            case "V":
                return "~Volt";
            case "A":
                return "~Ampere";
            case "W":
                return "~Watt";
            case "kWh":
                return "~Electricity";
            default:
                return ""; // Fallback
        }
    }
}
