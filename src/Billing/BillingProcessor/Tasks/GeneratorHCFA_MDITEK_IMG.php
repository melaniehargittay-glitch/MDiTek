<?php

namespace OpenEMR\Billing\BillingProcessor\Tasks;

use OpenEMR\Billing\BillingProcessor\BillingClaim;
use OpenEMR\Billing\Hcfa1500;

class GeneratorHCFA_MDITEK_IMG extends GeneratorHCFA_MDITEK
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
            $this->printToScreen("No HCFA generated for PID {$pid}");
            return;
        }

        $hcfa_image = $GLOBALS['images_static_absolute'] . "/cms1500.png";
        $pages = explode("\014", $pageText);

        foreach ($pages as $tmplines) {
            // Create new page for everything after the first
            if ($this->createNewPage) {
                $this->pdf->ezNewPage();
            } else {
                $this->createNewPage = true;
            }

            $this->pdf->ezSetY(
                $this->pdf->ez['pageHeight'] - $this->pdf->ez['topMargin']
            );

            // Stamp the CMS 1500 image on EVERY page
            if (file_exists($hcfa_image)) {
                $this->pdf->addPngFromFile($hcfa_image, 0, 0, 612, 792);
            }

            $this->pdf->ezText($tmplines, 12, [
                'justification' => 'left',
                'leading'       => 12,
            ]);
        }
    }
}