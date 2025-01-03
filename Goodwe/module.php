<?php

class Goodwe extends IPSModule
{
    public function __construct($InstanceID)
    {
        //Never delete this line!
        parent::__construct($InstanceID);
    }

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        $this->RegisterPropertyString("SelectedRegisters", "[]"); // Auswahl der Register
        $this->RegisterPropertyInteger("Poller", 0);

        $this->RegisterTimer("Poller", 0, "Goodwe_RequestRead(\$_IPS['TARGET']);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Liste der ausgewählten Register laden
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);

        // Prüfen, ob Register ausgewählt wurden
        if (!is_array($selectedRegisters) || empty($selectedRegisters)) {
            $this->SendDebug("Error", "No registers selected.", 0);
            return;
        }

        // Variablen für die ausgewählten Register erstellen
        foreach ($selectedRegisters as $register) {
            if (!isset($register['address']) || !isset($register['name'])) {
                $this->SendDebug("Error", "Invalid register entry: " . json_encode($register), 0);
                continue;
            }

            $ident = "Addr" . $register['address'];
            $this->RegisterVariableFloat($ident, $register['name'], "");
        }

        // Timer-Intervall setzen
        $this->SetTimerInterval("Poller", $this->ReadPropertyInteger("Poller"));
    }

    public function RequestRead()
    {
        $selectedRegisters = json_decode($this->ReadPropertyString("SelectedRegisters"), true);

        foreach ($selectedRegisters as $register) {
            if (!isset($register['address']) || !isset($register['name'])) {
                $this->SendDebug("Error", "Invalid register entry in RequestRead: " . json_encode($register), 0);
                continue;
            }

            $quantity = ($register['type'] === "U32" || $register['type'] === "S32") ? 2 : 1;

            $response = $this->SendDataToParent(json_encode([
                "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
                "Function" => 3,
                "Address"  => $register['address'],
                "Quantity" => $quantity,
                "Data"     => ""
            ]));

            if ($response === false || strlen($response) < (2 * $quantity + 2)) {
                $this->SendDebug("Error", "No or incomplete response for Register {$register['address']}", 0);
                continue;
            }

            $this->SendDebug("Raw Response for Register {$register['address']}", bin2hex($response), 0);
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
                    $this->SendDebug("Error", "Unknown type for Register {$register['address']}: {$register['type']}", 0);
                    continue;
            }

            $scaledValue = $value / $register['scale'];
            $this->SendDebug("Parsed Value for Register {$register['address']}", $scaledValue, 0);

            SetValue($this->GetIDForIdent("Addr" . $register['address']), $scaledValue);
        }
    }

    public function ReloadRegisters()
    {
        $registers = array_map(function ($register) {
            return [
                'address' => $register['address'],
                'name'    => $register['name']
            ];
        }, $this->Registers());

        $this->UpdateFormField('SelectedRegisters', 'values', json_encode($registers));
    }

    private function Registers()
    {
        return [
            ["address" => 35103, "name" => "PV1 Voltage", "type" => "U16", "unit" => "V", "scale" => 10, "quantity" => 1],
            ["address" => 35104, "name" => "PV1 Current", "type" => "U16", "unit" => "A", "scale" => 10, "quantity" => 1],
            ["address" => 35191, "name" => "Total PV Energy", "type" => "U32", "unit" => "kWh", "scale" => 10, "quantity" => 2],
            ["address" => 35107, "name" => "PV2 Voltage", "type" => "U16", "unit" => "V", "scale" => 10, "quantity" => 1],
            ["address" => 36025, "name" => "Smartmeter Power", "type" => "S32", "unit" => "W", "scale" => 1, "quantity" => 2]
        ];
    }
}
