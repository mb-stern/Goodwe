<?php
class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        $this->RegisterPropertyInteger("Poller", 0);
        $this->RegisterPropertyInteger("Phase", 1);
        $this->RegisterPropertyString("SelectedRegisters", "[]"); // JSON-Array für ausgewählte Register
        $this->RegisterTimer("Poller", 0, "Goodwe_RequestRead(\$_IPS['TARGET']);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Liste der ausgewählten Register
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);

        // Alle Variablen löschen, die nicht mehr benötigt werden
        foreach ($this->GetVariableList() as $variable) {
            $ident = $variable['ObjectIdent'];
            if (!in_array($ident, array_column($selectedRegisters, 'address'))) {
                $this->UnregisterVariable($ident);
            }
        }

        // Variablen für ausgewählte Register erstellen
        foreach ($selectedRegisters as $register) {
            $profileInfo = $this->GetVariableProfile($register['unit'], $register['scale']);
            $ident = "Addr" . $register['address'];

            // Variable registrieren
            switch ($profileInfo['type']) {
                case VARIABLETYPE_FLOAT:
                    $this->RegisterVariableFloat($ident, $register['name'], $profileInfo['profile']);
                    break;
                case VARIABLETYPE_INTEGER:
                    $this->RegisterVariableInteger($ident, $register['name'], $profileInfo['profile']);
                    break;
            }
        }

        $this->SetTimerInterval("Poller", $this->ReadPropertyInteger("Poller"));
    }

    public function RequestRead()
    {
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);

        foreach ($selectedRegisters as $register) {
            $quantity = ($register['type'] === "U32" || $register['type'] === "S32") ? 2 : 1;

            $response = $this->SendDataToParent(json_encode([
                "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
                "Function" => 3,
                "Address"  => $register['address'],
                "Quantity" => $quantity
            ]));

            if ($response === false || strlen($response) < (2 * $quantity + 2)) {
                $this->SendDebug("Error", "No or incomplete response for Register {$register['address']}", 0);
                continue;
            }

            $data = unpack("n*", substr($response, 2));
            $value = 0;

            switch ($register['type']) {
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
                    continue;
            }

            $scaledValue = $value / $register['scale'];
            SetValue($this->GetIDForIdent("Addr" . $register['address']), $scaledValue);
        }
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
            case "Hz":
                return ["profile" => "~Hertz", "type" => VARIABLETYPE_FLOAT];
            case "°C":
                return ["profile" => "~Temperature", "type" => VARIABLETYPE_FLOAT];
            case "kWh":
                return ["profile" => "~Electricity", "type" => VARIABLETYPE_FLOAT];
            case "%":
                return ["profile" => "~Battery.100", "type" => VARIABLETYPE_INTEGER];
            default:
                return ["profile" => "", "type" => VARIABLETYPE_FLOAT];
        }
    }

    private function GetVariableList()
    {
        return IPS_GetChildrenIDs($this->InstanceID);
    }
}
