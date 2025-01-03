<?php

class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        $this->RegisterPropertyString("SelectedRegisters", json_encode([]));
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->ReloadRegisters();
    
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        if (!is_array($selectedRegisters)) {
            return;
        }
    
        foreach ($selectedRegisters as $register) {
            if (isset($register['selected']) && $register['selected']) {
                $profileInfo = $this->GetVariableProfile($register['unit'], $register['scale']);
                $this->RegisterVariable($register, $profileInfo);
            }
        }
    }
    
    public function ReloadRegisters()
    {
        // Beispielhafte Daten; ersetze dies mit dem tatsächlichen Abruf der Register
        $registers = [
            ["address" => "0x01", "name" => "Register 1", "type" => "INT", "unit" => "V", "scale" => "1"],
            ["address" => "0x02", "name" => "Register 2", "type" => "FLOAT", "unit" => "A", "scale" => "0.1"],
            // Weitere Register...
        ];
    
        // Aktuelle Auswahl aus den Modul-Properties laden
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true) ?? [];
    
        // Werte für die Liste vorbereiten
        $values = [];
        foreach ($registers as $register) {
            $register['selected'] = in_array($register['address'], $selectedRegisters);
            $values[] = $register;
        }
    
        // Liste im Formular aktualisieren
        $this->UpdateFormField("AvailableRegisters", "values", json_encode($values));
    }
    
    public function SaveRegisters()
    {
        // Werte aus dem Formular lesen
        $formData = json_decode($this->GetBuffer("FormData"), true);
        $selectedRegisters = [];
    
        // Überprüfen, welche Register ausgewählt wurden
        if (isset($formData['AvailableRegisters'])) {
            foreach ($formData['AvailableRegisters'] as $register) {
                if ($register['selected']) {
                    $selectedRegisters[] = $register['address'];
                }
            }
        }
    
        // Ausgewählte Register in den Modul-Properties speichern
        $this->WritePropertyString("SelectedRegisters", json_encode($selectedRegisters));
    
        // Änderungen anwenden
        $this->ApplyChanges();
    }
    
    private function Registers()
    {
        return [
            ["address" => 35103, "name" => "PV1 Voltage", "type" => "U16", "unit" => "V", "scale" => 10],
            ["address" => 35104, "name" => "PV1 Current", "type" => "U16", "unit" => "A", "scale" => 10],
            ["address" => 35191, "name" => "Total PV Energy", "type" => "U32", "unit" => "kWh", "scale" => 10],
            ["address" => 35107, "name" => "PV2 Voltage", "type" => "U16", "unit" => "V", "scale" => 10],
            ["address" => 36025, "name" => "Smartmeter Power", "type" => "S32", "unit" => "W", "scale" => 1]
        ];
    }

    private function GetVariableProfile(string $unit, float $scale): array
    {
        switch ($unit) {
            case "V":
                return ["profile" => "~Volt", "type" => VARIABLETYPE_FLOAT];
            case "A":
                return ["profile" => "~Ampere", "type" => VARIABLETYPE_FLOAT];
            case "W":
                return ["profile" => "~Watt", "type" => VARIABLETYPE_FLOAT];
            case "kWh":
                return ["profile" => "~Electricity", "type" => VARIABLETYPE_FLOAT];
            default:
                return ["profile" => "", "type" => VARIABLETYPE_FLOAT];
        }
    }

    private function RegisterVariable($register, $profileInfo)
    {
        $ident = "Addr" . $register['address'];
        $this->RegisterVariableFloat(
            $ident,
            $register['name'],
            $profileInfo['profile']
        );
    }

    private function GetVariableList()
    {
        $variables = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $object = IPS_GetObject($childID);
            if ($object['ObjectType'] == 2) { // Variablen
                $variables[] = [
                    "ID" => $childID,
                    "Ident" => $object['ObjectIdent']
                ];
            }
        }
        return $variables;
    }

    private function RemoveVariable(string $ident)
    {
        $variableID = @$this->GetIDForIdent($ident);
        if ($variableID) {
            IPS_DeleteVariable($variableID);
        }
    }
}
