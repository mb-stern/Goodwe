<?php

class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        $this->RegisterPropertyInteger("Poller", 0);
        $this->RegisterPropertyString("SelectedRegisters", json_encode([])); // Initial leer
        $this->RegisterTimer("Poller", 0, "Goodwe_RequestRead(\$_IPS['TARGET']);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);

        // Bestehende Variablen entfernen
        foreach ($this->GetVariableList() as $variable) {
            $this->RemoveVariable($variable['Ident']);
        }

        // Neue Variablen erstellen
        foreach ($this->Registers() as $register) {
            if (in_array($register['address'], $selectedRegisters)) {
                $profileInfo = $this->GetVariableProfile($register['unit'], $register['scale']);
                $this->RegisterVariable($register, $profileInfo);
            }
        }

        $this->SetTimerInterval("Poller", $this->ReadPropertyInteger("Poller"));
    }

    public function ReloadRegisters()
    {
        $options = [];
        foreach ($this->Registers() as $register) {
            $options[] = [
                "label" => "{$register['name']} (Addr: {$register['address']})",
                "value" => $register['address']
            ];
        }

        $this->UpdateFormField("SelectRegisters", "options", json_encode($options));
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
        switch ($profileInfo['type']) {
            case VARIABLETYPE_FLOAT:
                $this->RegisterVariableFloat(
                    $ident,
                    $register['name'],
                    $profileInfo['profile']
                );
                break;
            case VARIABLETYPE_INTEGER:
                $this->RegisterVariableInteger(
                    $ident,
                    $register['name'],
                    $profileInfo['profile']
                );
                break;
        }
    }

    private function GetVariableList()
    {
        $variables = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $object = IPS_GetObject($childID);
            if ($object['ObjectType'] == 2) { // Nur Variablen berücksichtigen
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
