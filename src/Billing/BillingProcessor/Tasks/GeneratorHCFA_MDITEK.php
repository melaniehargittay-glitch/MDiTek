<?php

namespace OpenEMR\Billing\BillingProcessor\Tasks;

use OpenEMR\Billing\BillingProcessor\BillingClaim;
use OpenEMR\Billing\Hcfa1500;

class GeneratorHCFA_MDITEK extends GeneratorHCFA_PDF
{
    protected array $patientMap = [];
    protected array $processedPatients = [];

    public function setup(array $context): void
    {
        parent::setup($context);

        foreach ($context['claims'] as $claim) {
            $this->patientMap[$claim->getPid()][] =
                $claim->getEncounter();
        }
    }

    public function generate(BillingClaim $claim): void
    {
        $pid = $claim->getPid();

        // already printed this patient
        if (isset($this->processedPatients[$pid])) {
            return;
        }

        $this->processedPatients[$pid] = true;

        $encounters = $this->patientMap[$pid] ?? [];

        $hcfa = new Hcfa1500();

        $log = '';

        $pageText = $hcfa->genHcfa1500SelectedEncounters(
            $pid,
            $encounters,
            $log
        );

        $this->printToScreen(
            "PID={$pid} ENCOUNTERS=" . implode(',', $encounters)
        );

        if (empty($pageText)) {
            $this->printToScreen(
                "No HCFA generated for PID {$pid}"
            );
            return;
        }

        if ($this->createNewPage) {
            $this->pdf->ezNewPage();
        } else {
            $this->createNewPage = true;
        }

        $this->pdf->ezSetY(
            $this->pdf->ez['pageHeight'] - $this->pdf->ez['topMargin']
        );

        $this->pdf->ezText($pageText, 12, [
            'justification' => 'left',
            'leading'       => 12,
        ]);
    }
}