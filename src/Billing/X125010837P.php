<?php
/* X125010837P Class
 *
 * This program creates an X12 5010 837P file.
 *
 * @package OpenEMR
 * @author Rod Roark <rod@sunsetsystems.com>
 * @author Stephen Waite <stephen.waite@cmsvt.com>
 * @author Daniel Pflieger <daniel@mi-squared.com>, <daniel@growlingflea.com>
 * @copyright Copyright (c) 2009 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2018-2021 Stephen Waite <stephen.waite@cmsvt.com>
 * @copyright Copyright (c) 2021 Daniel Pflieger <daniel@mi-squared.com>, <daniel@growlingflea.com>
 * @link https://github.com/openemr/openemr/tree/master
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Billing;


use OpenEMR\Billing\Claim;

class X125010837P
{
    /*
     * @param  $pid
     * @param  $encounter
     * @param  $log
     * @param  false $encounter_claim
     * @param  $SEFLAG
     * @param  $HLcount
     * @param  $edicount
     * @param  $HLBillingPayToProvider Place-holder for utilizing multiple billing providers
     * @return string|string[]|null
     */

    public static function genX12837P(
        $pid,
        $encounter,
        &$log,
        $encounter_claim = false,
        $SEFLAG = false,
        $HLcount = 0,
        &$edicount = 0,
        $HLBillingPayToProvider = 1
    ) {
        $today = time();
        $out = '';
        $claim = new Claim($pid, $encounter);

        if ($GLOBALS['gen_x12_based_on_ins_co']) {
            $log .= "Generating directly to insurance claim $pid" . "-" . $encounter . " for ";
        } else {
            $log .= "Generating claim $pid" . "-" . $encounter . " for ";
        }
        $log .= $claim->patientFirstName() . ' ' .
        $claim->patientMiddleName() . ' ' .
        $claim->patientLastName() . ' on ' .
        date('Y-m-d H:i', $today) . ".\n";

        //error_log("X12 PARTNER: " . print_r($partner, true));
        $out .= "ISA" .
            "*" . $claim->x12gsisa01() .
            "*" . $claim->x12gsisa02() .
            "*" . $claim->x12gsisa03() .
            "*" . $claim->x12gsisa04() .
            "*" . $claim->x12gsisa05() .
            "*" . $claim->x12_sender_id() .
            "*" . $claim->x12gsisa07() .
            "*" . $claim->x12gsreceiverid() .
            "*" . "030911" .  // dummy data replace by billing_process.php
            "*" . "1630" . // ditto
            "*" . "^" .
            "*" . "00501" .
            "*" . "000000001" .
            "*" . $claim->x12gsisa14() .
            "*" . $claim->x12gsisa15() .
            "*:" .
            "~\n";

        $out .= "GS" .
            "*" . "HC" .
            "*" . $claim->x12gsgs02() .
            "*" . trim($claim->x12gs03()) .
            "*" . date('Ymd', $today) .
            "*" . date('Hi', $today) .
            "*" . "1" . // TODO add a tracking number
            "*" . "X" .
            "*" . $claim->x12gsversionstring() .
            "~\n";

        // one transaction set (ST) based on direct send to insurance companies global
        if (
            (
                $HLcount == 1 // HLcount passed in to function by GeneratorX12Direct class
                && !empty($GLOBALS['gen_x12_based_on_ins_co'])
            )
            || (
                empty($GLOBALS['gen_x12_based_on_ins_co'])
            )
        ) {
            $edicount = 0;
            ++$edicount;
            
            $out .= "ST" .
                "*" . "837" .
                "*" . "0021" .
                "*" . $claim->x12gsversionstring() .
                "~\n";

            ++$edicount;
            $out .= "BHT" .
                "*" . "0019" .                             // 0019 is required here
                "*" . "00" .                               // 00 = original transmission
                "*" . "0123" .                             // reference identification
                "*" . date('Ymd', $today) .           // transaction creation date
                "*" . date('Hi', $today) .            // transaction creation time
                "*" . ($encounter_claim ? "RP" : "CH") .  // RP = reporting, CH = chargeable
                "~\n";

            ++$edicount;
            // check entity type for NM*102 1 == person, 2 == non-person entity
            // SY = ssn so is 1 or person but we also check for a 3rd party submitter
            if ($claim->federalIdType() == "SY") {
                $out .= "NM1" . // Loop 1000A Submitter
                "*" . "41" .
                "*";
                // check for 3rd party submitter name entered in practice settings x12 partner
                if ($claim->x12_submitter_name()) {
                    // non-person entity
                    $out .= "2" .
                    "*" . $claim->x12_submitter_name() .
                    "*" .
                    "*" .
                    "*" .
                    "*" .
                    "*" . "46" .
                    "*" . $claim->x12_sender_id();
                // use provider name since using ssn as tax id
                // insurance companies may be deprecating use of ssn 7-10-21
                } else {
                    $firstName = $claim->providerFirstName();
                    $lastName = $claim->providerLastName();
                    $middleName = $claim->providerMiddleName();
                    $suffixName = $claim->providerSuffixName();
                    $out .= "1" .
                    "*" . $lastName .
                    "*" . $firstName .
                    "*" . $middleName .
                    "*" . // Name Prefix not used
                    "*" . $suffixName .
                    "*" . "46" .
                    "*" . $claim->billingFacilityETIN();
                }
            // non-person entity, use 2
            } else {
                $out .= "NM1" .
                "*" . "41" .
                "*" . "2" . "*" ;
                // check for 3rd party
                if ($claim->x12_submitter_name()) {
                    $out .=  $claim->x12_submitter_name() .
                    "*" .
                    "*" .
                    "*" .
                    "*" .
                    "*" . "46" .
                    "*" . $claim->x12_sender_id();
                // else use provider's group name
                } else {
                    $billingFacilityName = substr($claim->billingFacilityName(), 0, 60);
                    if ($billingFacilityName == '') {
                        $log .= "*** billing facility name in 1000A loop is empty\n";
                    }
                    $out .= $billingFacilityName .
                    "*" .
                    "*" .
                    "*" .
                    "*" .
                    "*" . "46" .
                    "*" . $claim->billingFacilityETIN();
                }
            }
            // close the NM1 segment
            $out .= "~\n";

            // Required Submitter Contact Information (1000A PER)

            ++$edicount;

            $out .= "PER"
                . "*" . "IC"
                . "*" . "OFFICE"
                . "*" . "TE"
                . "*" . "5175551212"
                . "~\n";

            // (no edicount increment here)

            // Continue directly to receiver
            ++$edicount;
            $out .= "NM1" . // Loop 1000B Receiver
                "*" . "40" .
                "*" . "2" .
                "*" . $claim->clearingHouseName() .
                "*" .
                "*" .
                "*" .
                "*" .
                "*" . "46" .
                "*" . $claim->clearingHouseETIN() .
                "~\n";
               
            ++$edicount;
            ++$HLcount;

            $out .= "HL" . // Loop 2000A Billing/Pay-To Provider HL Loop
            "*" . $HLcount .
            "*" .
            "*" . "20" .
            "*" . "1" . // 1 indicates there are child segments
            "~\n";

            if (
                empty($GLOBALS['gen_x12_based_on_ins_co'])
            ) {
                $HLBillingPayToProvider = $HLcount++;
            }

            // Situational PRV segment for provider taxonomy.
            if ($claim->facilityTaxonomy()) {
                ++$edicount;
                $out .= "PRV" .
                "*" . "BI" .
                "*" . "PXC" .
                "*" . $claim->facilityTaxonomy() .
                "~\n";
            }

            // Situational CUR segment (foreign currency information) omitted here.
            ++$edicount;
            if ($claim->federalIdType() == "SY") { // check for entity type like in 1000A
                $firstName = $claim->providerFirstName();
                $lastName = $claim->providerLastName();
                $middleName = $claim->providerMiddleName();
                $out .= "NM1" .
                "*" . "85" .
                "*" . "1" .
                "*" . $lastName .
                "*" . $firstName .
                "*" . $middleName .
                "*" . // Name Prefix not used
                "*";
            } else {
                $billingFacilityName = substr($claim->billingFacilityName(), 0, 60);
                if ($billingFacilityName == '') {
                    $log .= "*** billing facility name in 2010A loop is empty.\n";
                }
                $out .= "NM1" . // Loop 2010AA Billing Provider
                "*" . "85" .
                "*" . "2" .
                "*" . $billingFacilityName .
                "*" .
                "*" .
                "*" .
                "*";
            }
            if ($claim->billingFacilityNPI()) {
                $out .= "*XX*" . $claim->billingFacilityNPI();
            } else {
                $log .= "*** Billing facility has no NPI.\n";
            }
            $out .= "~\n";

            ++$edicount;
            $out .= "N3" .
            "*";
            if ($claim->billingFacilityStreet()) {
                $out .= $claim->billingFacilityStreet();
            } else {
                $log .= "*** Billing facility has no street.\n";
            }
            $out .= "~\n";

            ++$edicount;
            $out .= "N4" .
            "*";
            if ($claim->billingFacilityCity()) {
                $out .= $claim->billingFacilityCity();
            } else {
                $log .= "*** Billing facility has no city.\n";
            }
            $out .= "*";
            if ($claim->billingFacilityState()) {
                $out .= $claim->billingFacilityState();
            } else {
                $log .= "*** Billing facility has no state.\n";
            }
            $out .= "*";
            // X12 requires a 9 digit zip in loop 2010AA but we output it anyways
            if (strlen($claim->billingFacilityZip()) != 9) {
                $log .= "*** Billing facility zip is not 9 digits.\n";
            }
            $out .= $claim->billingFacilityZip();
            $out .= "~\n";

            if ($claim->billingFacilityNPI() && $claim->billingFacilityETIN()) {
                ++$edicount;
                $out .= "REF";
                if ($claim->federalIdType()) {
                    $out .= "*" . $claim->federalIdType();
                } else {
                    $out .= "*EI"; // For dealing with the situation before adding TaxId type In facility.
                }
                $out .= "*" . $claim->billingFacilityETIN() . "~\n";
            } else {
                $log .= "*** No billing facility NPI and/or ETIN.\n";
            }
            if ($claim->providerNumberType() && $claim->providerNumber() && !$claim->billingFacilityNPI()) {
                ++$edicount;
                $out .= "REF" .
                "*" . $claim->providerNumberType() .
                "*" . $claim->providerNumber() .
                "~\n";
            } elseif ($claim->providerNumber() && !$claim->providerNumberType()) {
                $log .= "*** Payer-specific provider insurance number is present but has no type assigned.\n";
            }

            // Situational PER*1C segment omitted.

            // Pay-To Address defaults to billing provider and is no longer required in 5010 but may be useful
            if ($claim->pay_to_provider != '') {
                ++$edicount;
                $billingFacilityName = substr($claim->billingFacilityName(), 0, 60);
                $out .= "NM1" .       // Loop 2010AB Pay-To Provider
                "*" . "87" .
                "*" . "2" .
                "*" . $billingFacilityName .
                "*" .
                "*" .
                "*" .
                "*";
                if ($claim->billingFacilityNPI()) {
                    $out .= "*XX*" . $claim->billingFacilityNPI();
                } else {
                    $log .= "*** Pay to provider has no NPI.\n";
                }
                $out .= "~\n";

                ++$edicount;
                $out .= "N3" .
                "*";
                if ($claim->billingFacilityStreet()) {
                    $out .= $claim->billingFacilityStreet();
                } else {
                    $log .= "*** Pay to provider has no street.\n";
                }
                $out .= "~\n";

                ++$edicount;
                $out .= "N4" .
                "*";
                if ($claim->billingFacilityCity()) {
                    $out .= $claim->billingFacilityCity();
                } else {
                    $log .= "*** Pay to provider has no city.\n";
                }
                $out .= "*";
                if ($claim->billingFacilityState()) {
                    $out .= $claim->billingFacilityState();
                } else {
                    $log .= "*** Pay to provider has no state.\n";
                }
                $out .= "*";
                // X12 requires a 9 digit zip but we output it anyways
                if (strlen($claim->billingFacilityZip()) != 9) {
                    $log .= "*** Pay to provider zip is not 9 digits.\n";
                }
                $out .= $claim->billingFacilityZip();
                $out .= "~\n";
            }

            // Loop 2010AC Pay-To Plan Name omitted.  Includes:
            // NM1*PE, N3, N4, REF*2U, REF*EI
        }

        $PatientHL = $claim->isSelfOfInsured() ? 0 : 1;
        $HLSubscriber = $HLcount++;

        ++$edicount;
        $out .= "HL" .        // Loop 2000B Subscriber HL Loop
            "*" . $HLSubscriber .
            "*" . $HLBillingPayToProvider .
            "*" . "22" .
            "*" . $PatientHL .
            "~\n";

        if (!$claim->payerSequence()) {
            $log .= "*** Error: Insurance information is missing!\n";
        }

        ++$edicount;
        $out .= "SBR" .    // Subscriber Information
            "*" . $claim->payerSequence() .
            "*" . ($claim->isSelfOfInsured() ? '18' : '') .
            "*" . $claim->groupNumber() .
            "*" . ($claim->groupNumber() ? '' : $claim->groupName()) . // if groupNumber no groupName
            "*" . $claim->insuredTypeCode() . // applies for secondary medicare
            "*" .
            "*" .
            "*" .
            "*" . $claim->claimType() .
            "~\n";

        // Segment PAT omitted.

        ++$edicount;
        $out .= "NM1" .       // Loop 2010BA Subscriber
            "*" . "IL" .
            "*" . "1"; // 1 = person, 2 = non-person
        if ($claim->insuredLastName()) {
            $out .= "*" .
                $claim->insuredLastName();
        } else {
            $log .= "*** Missing insured last name.\n";
        }
        $out .= "*";
        if ($claim->insuredFirstName()) {
            $out .= $claim->insuredFirstName();
        } else {
            $log .= "*** Missing insured first name.\n";
        }
        $out .= "*" .
            $claim->insuredMiddleName() .
            "*" .
            "*" . // Name Suffix not used
            "*" . "MI" .
            // "MI" = Member Identification Number
            // "II" = Standard Unique Health Identifier, "Required if the
            //        HIPAA Individual Patient Identifier is mandated use."
            //        Here we presume that is not true yet.
            "*";
        if ($claim->policyNumber()) {
            $out .= $claim->policyNumber();
        } else {
            $log .= "*** Missing policy number.\n";
        }
        $out .= "~\n";

        // For 5010, further subscriber info is sent only if they are the patient.
        if ($claim->isSelfOfInsured()) {
            ++$edicount;
            $out .= "N3" .
                "*";
            if ($claim->insuredStreet()) {
                $out .= $claim->insuredStreet();
            } else {
                $log .= "*** Missing insured street.\n";
            }
            $out .= "~\n";

            ++$edicount;
            $out .= "N4" .
                "*";
            if ($claim->insuredCity()) {
                $out .= $claim->insuredCity();
            } else {
                $log .= "*** Missing insured city.\n";
            }
            $out .= "*";
            if ($claim->insuredState()) {
                $out .= $claim->insuredState();
            } else {
                $log .= "*** Missing insured state.\n";
            }
            $out .= "*";
            if (
                !(
                    (strlen($claim->x12Zip($claim->insuredZip())) == 5)
                    || (strlen($claim->x12Zip($claim->insuredZip())) == 9)
                )
            ) {
                $log .= "*** Insured zip is not 5 or 9 digits.\n";
            }
            $out .= $claim->x12Zip($claim->insuredZip());
            $out .= "~\n";

            ++$edicount;
            $out .= "DMG" .
            "*" .
            "D8" .
            "*";

        $dob = $claim->insuredDOB();

        if (empty($dob)) {
            $dob = $claim->patientDOB();
        }

        if (!empty($dob)) {
            $out .= $dob;
        } else {
            $log .= "*** Missing insured DOB.\n";
        }

        $out .= "*";

        $sex = $claim->insuredSex();

        if (empty($sex)) {
            $sex = $claim->patientSex();
        }

        if (!empty($sex)) {
            $out .= $sex;
        } else {
            $log .= "*** Missing insured sex.\n";
        }

        $out .= "~\n";
        }

        // Segment REF*SY (Subscriber Secondary Identification) omitted.
        // Segment REF*Y4 (Property and Casualty Claim Number) omitted.
        // Segment PER*IC (Property and Casualty Subscriber Contact Information) omitted.

        ++$edicount;
        $payerName = substr($claim->payerName(), 0, 60);
        $out .= "NM1" .       // Loop 2010BB Payer
            "*" . "PR" .
            "*" . "2" .
            "*";
        if ($payerName) {
            $out .= $payerName;
        } else {
            $log .= "*** Missing payer name.\n";
        }
        $out .= "*" .
            "*" .
            "*" .
            "*" .
            "*" . "PI" .
            "*" . ($encounter_claim ? $claim->payerAltID() : $claim->payerID());
        if (!$claim->payerID()) {
            $log .= "*** Payer ID is missing for payer '";
        }
        $out .= "~\n";

        ++$edicount;
        $out .= "N3" .
            "*";
        if ($claim->payerStreet()) {
            $out .= $claim->payerStreet();
        } else {
            $log .= "*** Missing payer street.\n";
        }
        $out .= "~\n";

        ++$edicount;
        $out .= "N4" .
            "*";
        if ($claim->payerCity()) {
            $out .= $claim->payerCity();
        } else {
            $log .= "*** Missing payer city.\n";
        }
        $out .= "*";
        if ($claim->payerState()) {
            $out .= $claim->payerState();
        } else {
            $log .= "*** Missing payer state.\n";
        }
        $out .= "*";
        if (
            (strlen($claim->payerZip()) == 5)
            || (strlen($claim->payerZip()) == 9)
        ) {
            $out .= $claim->x12Zip($claim->payerZip());
        } else {
            $log .= "*** Payer zip is not 5 or 9 digits.\n";
        }
        $out .= "~\n";

        // Segment REF (Payer Secondary Identification) omitted.
        // Segment REF (Billing Provider Secondary Identification) omitted.

        if (!$claim->isSelfOfInsured()) {
            ++$edicount;
            $out .= "HL" .        // Loop 2000C Patient Information
                "*" . $HLcount .
                "*" . $HLSubscriber .
                "*" . "23" .
                "*" . "0" .
                "~\n";

            $HLcount++;
            ++$edicount;
            $out .= "PAT" .
                "*" . $claim->insuredRelationship() .
                "~\n";

            ++$edicount;
            $out .= "NM1" .       // Loop 2010CA Patient
                "*" . "QC" .
                "*" . "1" .
                "*";
            if ($claim->patientLastName()) {
                $out .= $claim->patientLastName();
            } else {
                $log .= "*** Missing patient last name.\n";
            }
            $out .=  "*";
            if ($claim->patientFirstName()) {
                $out .= $claim->patientFirstName();
            } else {
                $log .= "*** Missing patient first name.\n";
            }
            if ($claim->patientMiddleName() !== '') {
                $out .= "*" . $claim->patientMiddleName();
            }
            $out .= "~\n";

            ++$edicount;
            $out .= "N3" .
                "*";
            if ($claim->patientStreet()) {
                $out .= $claim->patientStreet();
            } else {
                $log .= "*** Missing patient street.\n";
            }
            $out .= "~\n";

            ++$edicount;
            $out .= "N4" .
                "*";
            if ($claim->patientCity()) {
                $out .= $claim->patientCity();
            } else {
                $log .= "*** Missing patient city.\n";
            }
            $out .= "*";
            if ($claim->patientState()) {
                $out .= $claim->patientState();
            } else {
                $log .= "*** Missing patient state.\n";
            }
            $out .= "*";
            if (
                (strlen($claim->patientZip()) == 5)
                || (strlen($claim->patientZip()) == 9)
            ) {
                $out .= $claim->patientZip();
            } else {
                $log .= "*** Patient zip is not 5 or 9 digits.\n";
            }
            $out .= "~\n";

            ++$edicount;
            $out .= "DMG" .
                "*" . "D8" .
                "*";
            if ($claim->patientDOB()) {
                $out .= $claim->patientDOB();
            } else {
                $log .= "*** Missing patient DOB.\n";
            }
            $out .= "*";
            if ($claim->patientSex()) {
                $out .= $claim->patientSex();
            } else {
                $log .= "*** Missing patient sex.\n";
            }
            $out .= "~\n";

            // Segment REF*Y4 (Property and Casualty Claim Number) omitted.
            // Segment REF (Property and Casualty Patient Identifier) omitted.
            // Segment PER (Property and Casualty Patient Contact Information) omitted.
        } // end of patient different from insured

        $proccount = $claim->procCount();
        $clm_total_charges = 0;
        for ($prockey = 0; $prockey < $proccount; ++$prockey) {
            $clm_total_charges += $claim->cptCharges($prockey);
        }
        if (!$clm_total_charges) {
            $log .= "*** This claim has no charges!\n";
        }

        ++$edicount;
        $out .= "CLM" .    // Loop 2300 Claim
            "*" . $pid . "-" . $encounter .
            "*" . sprintf("%.2f", $clm_total_charges) .
            "*" .
            "*" .
            "*" . sprintf('%02d', $claim->facilityPOS()) . ":" . "B" . ":" . $claim->frequencyTypeCode() .
            "*" . "Y" .
            "*" . ($claim->billingFacilityAssignment() ? 'A' : 'C') .
            "*" . ($claim->billingFacilityAssignment() ? 'Y' : 'N') .
            "*" . "Y" .
            "~\n";

        // above is for historical use of encounter onset date, now in misc_billing_options
        // Segment DTP*431 (Onset of Current Symptoms or Illness)
        // Segment DTP*484 (Last Menstrual Period Date)

        if (
            $claim->onsetDate()
            && $claim->onsetDate() !== $claim->serviceDate()
            && $claim->onsetDateValid()
        ) {
            ++$edicount;
            $out .= "DTP" .       // Date of Onset
                "*" . "431" .
                "*" . "D8" .
                "*" . $claim->onsetDate() .
                "~\n";
        } elseif (
            $claim->miscOnsetDate()
            && $claim->miscOnsetDate() !== $claim->serviceDate()
            && $claim->box14Qualifier()
            && $claim->miscOnsetDateValid()
        ) {
            ++$edicount;
            $out .= "DTP" .
                "*" . $claim->box14Qualifier() .
                "*" . "D8" .
                "*" . $claim->miscOnsetDate() .
                "~\n";
        }

        // Segment DTP*304 (Last Seen Date)
        // Segment DTP*453 (Acute Manifestation Date)
        // Segment DTP*439 (Accident Date)
        // Segment DTP*455 (Last X-Ray Date)
        // Segment DTP*471 (Hearing and Vision Prescription Date)
        // Segment DTP*314 (Disability) omitted.
        // Segment DTP*360 (Initial Disability Period Start) omitted.
        // Segment DTP*361 (Initial Disability Period End) omitted.
        // Segment DTP*297 (Last Worked Date)
        // Segment DTP*296 (Authorized Return to Work Date)

        // Segment DTP*454 (Initial Treatment Date)

        if ($claim->dateInitialTreatment() && ($claim->box15Qualifier()) && ($claim->dateInitialTreatmentValid())) {
            ++$edicount;
            $out .= "DTP" .       // Date Last Seen
                "*" . $claim->box15Qualifier() .
                "*" . "D8" .
                "*" . $claim->dateInitialTreatment() .
                "~\n";
        }

        if (strcmp($claim->facilityPOS(), '21') == 0 && $claim->onsetDateValid()) {
            ++$edicount;
            $out .= "DTP" .     // Date of Hospitalization
                "*" . "435" .
                "*" . "D8" .
                "*" . $claim->onsetDate() .
                "~\n";
        }

       /* if ($claim->lastXrayDateValid()) {

            ++$edicount;
        
            $out .= "DTP"
                . "*455"
                . "*D8"
                . "*" . $claim->lastXrayDate()
                . "~\n";
        } */

        // above is for historical use of encounter onset date, now in misc_billing_options
        if (strcmp($claim->facilityPOS(), '21') == 0 && $claim->hospitalizedFromDateValid()) {
            ++$edicount;
            $out .= "DTP" .     // Date of Admission
                "*" . "435" .
                "*" . "D8" .
                "*" . $claim->hospitalizedFrom() .
                "~\n";
        }

        // Segment DTP*096 (Discharge Date)
        if (strcmp($claim->facilityPOS(), '21') == 0 && $claim->hospitalizedToDateValid()) {
            ++$edicount;
            $out .= "DTP" .     // Date of Discharge
                "*" . "96" .
                "*" . "D8" .
                "*" . $claim->hospitalizedTo() .
                "~\n";
        }

        // Segments DTP (Assumed and Relinquished Care Dates) omitted.
        // Segment DTP*444 (Property and Casualty Date of First Contact) omitted.
        // Segment DTP*050 (Repricer Received Date) omitted.
        // Segment PWK (Claim Supplemental Information) omitted.
        // Segment CN1 (Contract Information) omitted.

        $patientpaid = $claim->patientPaidAmount();
        if ($patientpaid != 0) {
            ++$edicount;
            $out .= "AMT" .     // Patient paid amount. Page 190/220.
                "*" . "F5" .
                "*" . $patientpaid .
                "~\n";
        }

        // Segment REF*4N (Service Authorization Exception Code) omitted.
        // Segment REF*F5 (Mandatory Medicare Crossover Indicator) omitted.
        // Segment REF*EW (Mammography Certification Number) omitted.
        // Segment REF*9F (Referral Number) omitted.

        if ($claim->priorAuth()) {
            ++$edicount;
            $out .= "REF" .     // Prior Authorization Number
                "*" . "G1" .
                "*" . $claim->priorAuth() .
                "~\n";
        }

        // Segment REF*F8 Payer Claim Control Number for claim re-submission.icn_resubmission_number
        if (strlen(trim($claim->billing_options['icn_resubmission_number'] ?? '')) > 3) {
            ++$edicount;
            error_log("Method 1: " . errorLogEscape($claim->billing_options['icn_resubmission_number']), 0);
            $out .= "REF" .
                "*" . "F8" .
                "*" . $claim->icnResubmissionNumber() .
                "~\n";
        }

        if ($claim->cliaCode() && ($claim->claimType() === 'MB')) {
            // Required by Medicare when in-house labs are done.
            ++$edicount;
            $out .= "REF" .     // Clinical Laboratory Improvement Amendment Number
                "*" . "X4" .
                "*" . $claim->cliaCode() .
                "~\n";
        }

        // Segment REF*9A (Repriced Claim Number) omitted.
        // Segment REF*9C (Adjusted Repriced Claim Number) omitted.
        // Segment REF*LX (Investigational Device Exemption Number) omitted.
        // Segment REF*D9 (Claim Identifier for Transmission Intermediaries) omitted.
        // Segment REF*EA (Medical Record Number) omitted.
        // Segment REF*P4 (Demonstration Project Identifier) omitted.
        // Segment REF*1J (Care Plan Oversight) omitted.
        // Segment K3 (File Information) omitted.
        if ($claim->additionalNotes()) {
            // Claim note.
            ++$edicount;
            $out .= "NTE" .     // comments box 19
                "*" . "ADD" .
                "*" . $claim->additionalNotes() .
                "~\n";
        }

        // Segment CR1 (Ambulance Transport Information) omitted.
        // Segment CR2 (Spinal Manipulation Service Information) omitted.
        // Segment CRC (Ambulance Certification) omitted.
        // Segment CRC (Patient Condition Information: Vision) omitted.
        // Segment CRC (Homebound Indicator) omitted.
        // Segment CRC (EPSDT Referral).
        if ($claim->epsdtFlag()) {
            ++$edicount;
            $out .= "CRC" .
                "*" . "ZZ" .
                "*" . "Y" .
                "*" . $claim->medicaidReferralCode() .
                "~\n";
        }

        // Diagnoses, up to $max_per_seg per HI segment.
        $max_per_seg = 12;

        $da = [];

$res = sqlStatement("
    SELECT diagnosis
    FROM lists
    WHERE pid = ?
    AND type IN ('medical_problem','diagnosis')
    AND activity = 1
    AND diagnosis IS NOT NULL
    AND diagnosis != ''
    ORDER BY date DESC, id DESC
    LIMIT 4
", [$pid]);

while ($row = sqlFetchArray($res)) {

    $raw = $row['diagnosis'];

    // 🔥 Split multiple diagnoses in one field
    $parts = preg_split('/[;\n]+/', $raw);

    foreach ($parts as $part) {

        $part = trim($part);
        if (empty($part)) continue;

        // Extract after ICD10:
        if (stripos($part, 'ICD10:') === 0) {
            $tmp = explode(':', $part);
            $code = $tmp[1] ?? '';
        } else {
            $code = $part;
        }

        // Clean
        $code = strtoupper($code);
        $code = str_replace('.', '', $code);
        $code = preg_replace('/[^A-Z0-9]/', '', $code);

        // Validate
        if (!preg_match('/^[A-Z][0-9]/', $code)) continue;

        if (!in_array($code, $da, true)) {
            $da[] = $code;
        }

        // Stop at 4 diagnoses max (EDI limit)
        if (count($da) >= 4) {
            break 2;
        }
    }

        }
        if ($claim->diagtype == "ICD9") {
            $diag_type_code = 'BK';
        } else {
            $diag_type_code = 'ABK';
        }
        $tmp = 0;
        foreach ($da as $diag) {
            if ($tmp % $max_per_seg == 0) {
                if ($tmp) {
                    $out .= "~\n";
                }
                ++$edicount;
                $out .= "HI";         // Health Diagnosis Codes
            }
            $diag = preg_replace('/[^A-Z0-9]/', '', strtoupper($diag));
            $diag = strtoupper($diag);

            // Remove ICD10 if appended
            $diag = str_replace('ICD10', '', $diag);

            // Remove dots
            $diag = str_replace('.', '', $diag);

            // Remove any junk
            $diag = preg_replace('/[^A-Z0-9]/', '', $diag);

            // Final safety: must look like ICD
            if (!preg_match('/^[A-Z][0-9]/', $diag)) {
                continue;
            }

            $out .= "*" . $diag_type_code . ":" . $diag;
            if ($claim->diagtype == "ICD9") {
                $diag_type_code = 'BF';
            } else {
                $diag_type_code = 'ABF';
            }
            ++$tmp;
        }

        if ($tmp) {
            $out .= "~\n";
        }

        // Segment HI*BP (Anesthesia Related Procedure) omitted.
        // Segment HI*BG (Condition Information) omitted.
        // Segment HCP (Claim Pricing/Repricing Information) omitted.

        // Only send a referring provider when one actually exists.
        if (
            (int)$claim->getReferrerId() > 0 &&
            $claim->referrerLastName() &&
            $claim->referrerNPI()
        ) {
            ++$edicount;
            $out .= "NM1"
                . "*DN"
                . "*1"
                . "*"
                . $claim->referrerLastName()
                . "*"
                . $claim->referrerFirstName()
                . "*"
                . $claim->referrerMiddleName()
                . "**"
                . "*XX*"
                . $claim->referrerNPI()
                . "~\n";
        }
            
// Some payers (Priority Health) require 2310B Rendering Provider
// even when the Rendering Provider NPI matches the Billing Provider NPI.
// Most payers (including some BCBS plans) reject duplicate Billing/Rendering NPIs.

$sendRendering =
    $claim->providerNPIValid()
    && (
        trim((string)$claim->payerID()) === '38217'
        || $claim->providerNPI() != $claim->billingFacilityNPI()
    );
    
   
if ($sendRendering) {

    ++$edicount;
    $out .= "NM1"
        . "*" . "82"
        . "*" . "1"
        . "*";

    if ($claim->providerLastName()) {
        $out .= $claim->providerLastName();
    } else {
        $log .= "*** Missing provider last name.\n";
    }

    $out .= "*";

    if ($claim->providerFirstName()) {
        $out .= $claim->providerFirstName();
    } else {
        $log .= "*** Missing provider first name.\n";
    }

    $out .= "*"
        . $claim->providerMiddleName()
        . "*"
        . "*";

    if ($claim->providerNPI()) {
        $out .= "*XX*" . $claim->providerNPI();
    } else {
        $log .= "*** Rendering provider has no NPI.\n";
    }

    $out .= "~\n";

    if ($claim->providerTaxonomy()) {
        ++$edicount;
        $out .= "PRV"
            . "*" . "PE"
            . "*" . "PXC"
            . "*" . $claim->providerTaxonomy()
            . "~\n";
    } else {
        $log .= "*** Performing provider has no taxonomy code.\n";
    }
} else {
    $log .= "*** Skipping 2310B because rendering provider matches billing provider and payer does not require it.\n";
}

// Loop 2310C - Service Facility Location
// Priority Health accepts/sometimes expects this loop even when the
// Service Facility and Billing Provider are the same.

if ($claim->facilityPOS() != 12) {

    $facilityName   = substr($claim->facilityName() ?: $claim->billingFacilityName(), 0, 60);
    $facilityStreet = $claim->facilityStreet() ?: $claim->billingFacilityStreet();
    $facilityCity   = $claim->facilityCity() ?: $claim->billingFacilityCity();
    $facilityState  = $claim->facilityState() ?: $claim->billingFacilityState();
    $facilityZip    = $claim->facilityZip() ?: $claim->billingFacilityZip();
    $facilityNPI    = $claim->facilityNPI() ?: $claim->billingFacilityNPI();
    $facilityETIN   = $claim->facilityETIN() ?: $claim->billingFacilityETIN();

    if ($facilityNPI || $facilityETIN) {

        ++$edicount;

        $out .= "NM1"
            . "*" . "77"
            . "*" . "2"
            . "*" . $facilityName
            . "*"
            . "*"
            . "*"
            . "*";

        if ($facilityNPI) {
            $out .= "*XX*" . $facilityNPI;
        } else {
            $out .= "*24*" . $facilityETIN;
        }

        $out .= "~\n";

        if (!empty($facilityStreet)) {
            ++$edicount;
            $out .= "N3"
                . "*" . $facilityStreet
                . "~\n";
        } else {
            $log .= "*** Missing service facility street.\n";
        }

        ++$edicount;
        $out .= "N4"
            . "*" . $facilityCity
            . "*" . $facilityState
            . "*" . $claim->x12Zip($facilityZip)
            . "~\n";

    } else {
        $log .= "*** No service facility NPI or ETIN available.\n";
    }
}


        // Segment REF (Service Facility Location Secondary Identification) omitted.
        // Segment PER (Service Facility Contact Information) omitted.

        // Loop 2310D, Supervising Provider
        if (! empty($claim->supervisorLastName())) {
            ++$edicount;
            $out .= "NM1" .
                "*" . "DQ" . // Supervising Physician
                "*" . "1" .  // Person
                "*" . $claim->supervisorLastName() .
                "*" . $claim->supervisorFirstName() .
                "*" . $claim->supervisorMiddleName() .
                "*" .   // NM106 not used
                "*";    // Name Suffix not used
            if ($claim->supervisorNPI()) {
                $out .=
                    "*" . "XX" .
                    "*" . $claim->supervisorNPI();
            } else {
                $log .= "*** Supervising Provider has no NPI.\n";
            }
            $out .= "~\n";

            if ($claim->supervisorNumber()) {
                ++$edicount;
                $out .= "REF" .
                    "*" . $claim->supervisorNumberType() .
                    "*" . $claim->supervisorNumber() .
                    "~\n";
            }
        }

        // Segments NM1*PW, N3, N4 (Ambulance Pick-Up Location) omitted.
        // Segments NM1*45, N3, N4 (Ambulance Drop-Off Location) omitted.

        // Loops 2320 and 2330, other subscriber/payer information.
        // Remember that insurance index 0 is always for the payer being billed
        // by this claim, and 1 and above are always for the "other" payers.

        for ($ins = 1; $ins < $claim->payerCount(); ++$ins) {
            $tmp1 = $claim->claimType($ins);
            $tmp2 = 'C1'; // Here a kludge. See page 321.
            if ($tmp1 === 'CI') {
                $tmp2 = 'C1';
            }
            if ($tmp1 === 'AM') {
                $tmp2 = 'AP';
            }
            if ($tmp1 === 'HM') {
                $tmp2 = 'HM';
            }
            if ($tmp1 === 'MB') {
                $tmp2 = 'MB';
            }
            if ($tmp1 === 'MC') {
                $tmp2 = 'MC';
            }
            if ($tmp1 === '09') {
                $tmp2 = 'PP';
            }


             // 🔥 BLOCK EMPTY SECONDARY
             $prevPaid = $claim->payerTotals($ins - 1);
             $prevAdj  = $claim->payerAdjustments($ins - 1);
             
             // Check if adjustments actually have values
             $hasRealAdj = false;
             if (!empty($prevAdj)) {
                 foreach ($prevAdj as $adj) {
                     if (!empty($adj[2]) && (float)$adj[2] != 0) {
                         $hasRealAdj = true;
                         break;
                     }
                 }
             }
             
             // 🚫 Skip if no payment AND no REAL adjustments
             if (
                 (empty($prevPaid) || (float)($prevPaid[1] ?? 0) <= 0)
                 && !$hasRealAdj
             ) {
                 continue;
             }


            ++$edicount;
            $out .= "SBR" . // Loop 2320, Subscriber Information - page 297/318
                "*" . $claim->payerSequence($ins) .
                "*" . $claim->insuredRelationship($ins) .
                "*" . $claim->groupNumber($ins) .
                "*" . ($claim->groupNumber() ? '' : $claim->groupName()) .
                "*" . $claim->insuredTypeCode($ins) .
                "*" .
                "*" .
                "*" .
                "*" . $claim->claimType($ins) .
                "~\n";

            // Things that apply only to previous payers, not future payers.
            if ($claim->payerSequence($ins) < $claim->payerSequence()) {
                // Generate claim-level adjustments.
                $aarr = $claim->payerAdjustments($ins);
                foreach ($aarr as $a) {
                    ++$edicount;
                    $out .= "CAS" . // Previous payer's claim-level adjustments. Page 301/323.
                        "*" . $a[1] .
                        "*" . $a[2] .
                        "*" . $a[3] .
                        "~\n";
                }

                $payerpaid = $claim->payerTotals($ins);

                // AMT*D (Claim Paid Amount) is mandatory for COB/supplemental claims
                // per 5010A1 spec — must be present even when prior payer paid $0.00.
                ++$edicount;
                $amt = rtrim(rtrim(number_format((float)($payerpaid[1] ?? 0), 2, '.', ''), '0'), '.');
                if ($amt === '' || $amt === '.') {
                    $amt = '0';
                }
                $out .= "AMT" .
                    "*" . "D" .
                    "*" . $amt .
                    "~\n";
                // Segment AMT*A8 (COB Total Non-Covered Amount) omitted.
                // Segment AMT*EAF (Remaining Patient Liability) omitted.
            }   // End of things that apply only to previous payers.

            ++$edicount;
            $out .= "OI" .  // Other Insurance Coverage Information. Page 310/344.
                "*" .
                "*" .
                "*" . ($claim->billingFacilityAssignment($ins) ? 'Y' : 'N') .
                // For this next item, the 5010 example in the spec does not match its
                // description.  So this might be wrong.
                "*" .
                "*" .
                "*" .
                "Y" .
                "~\n";

            // Segment MOA (Medicare Outpatient Adjudication) omitted.
            ++$edicount;
            $out .= "NM1" . // Loop 2330A Subscriber info for other insco. Page 315/350.
                "*" . "IL" .
                "*" . "1" .
                "*";
            if ($claim->insuredLastName($ins)) {
                $out .= $claim->insuredLastName($ins);
            } else {
                $log .= "*** Missing other insco insured last name.\n";
            }
            $out .= "*";
            if ($claim->insuredFirstName($ins)) {
                $out .= $claim->insuredFirstName($ins);
            } else {
                $log .= "*** Missing other insco insured first name.\n";
            }
            $out .= "*" .
                $claim->insuredMiddleName($ins) .
                "*" .
                "*" .
                "*" . "MI" .
                "*";
            if ($claim->policyNumber($ins)) {
                $out .= $claim->policyNumber($ins);
            } else {
                $log .= "*** Missing other insco policy number.\n";
            }
            $out .= "~\n";

            ++$edicount;
            $out .= "N3" .
                "*";
            if ($claim->insuredStreet($ins)) {
                $out .= $claim->insuredStreet($ins);
            } else {
                $log .= "*** Missing other insco insured street.\n";
            }
            $out .= "~\n";

            ++$edicount;
            $out .= "N4" .
                "*";
            if ($claim->insuredCity($ins)) {
                $out .= $claim->insuredCity($ins);
            } else {
                $log .= "*** Missing other insco insured city.\n";
            }
            $out .= "*";
            if ($claim->insuredState($ins)) {
                $out .= $claim->insuredState($ins);
            } else {
                $log .= "*** Missing other insco insured state.\n";
            }
            $out .= "*";
            if (
                strlen($claim->insuredZip($ins)) == 5
                || strlen($claim->insuredZip($ins)) == 9
            ) {
                $out .= $claim->insuredZip($ins);
            } else {
                $log .= "*** Other insco insured zip is not 5 or 9 digits.\n";
            }
            $out .= "~\n";

            // Segment REF (Other Subscriber Secondary Identification) omitted.
            ++$edicount;
            $payerName = substr($claim->payerName($ins), 0, 60);
            $out .= "NM1" . // Loop 2330B Payer info for other insco. Page 322/359.
                "*" . "PR" .
                "*" . "2" .
                "*";
            if ($payerName) {
                $out .= $payerName;
            } else {
                $log .= "*** Missing other insco payer name.\n";
            }
            $out .= "*" .
                "*" .
                "*" .
                "*" .
                "*" . "PI" .
                "*";
            if ($claim->payerID($ins)) {
                $out .= $claim->payerID($ins);
            } else {
                $log .= "*** Missing other insco payer id.\n";
            }
            $out .= "~\n";

            ++$edicount;
            $out .= "N3" .
                "*";
            if ($claim->payerStreet($ins)) {
                $out .= $claim->payerStreet($ins);
            } else {
                $log .= "*** Missing other insco street.\n";
            }
            $out .= "~\n";

            ++$edicount;
            $out .= "N4" .
                "*";
            if ($claim->payerCity($ins)) {
                $out .= $claim->payerCity($ins);
            } else {
                $log .= "*** Missing other insco city.\n";
            }
            $out .= "*";
            if ($claim->payerState($ins)) {
                $out .= $claim->payerState($ins);
            } else {
                $log .= "*** Missing other payer state.\n";
            }
            $out .= "*";
            if (
                (strlen($claim->payerZip($ins)) == 5)
                || (strlen($claim->payerZip($ins)) == 9)
            ) {
                $out .= $claim->x12Zip($claim->payerZip($ins));
            } else {
                $log .= "*** Other payer zip is not 5 or 9 digits.\n";
            }
            $out .= "~\n";

            // Segment DTP*573 (Claim Check or Remittance Date) omitted.
            // Segment REF (Other Payer Secondary Identifier) omitted.
            // Segment REF*G1 (Other Payer Prior Authorization Number) omitted.
            // Segment REF*9F (Other Payer Referral Number) omitted.
            // Segment REF*T4 (Other Payer Claim Adjustment Indicator) omitted.
            // Segment REF*F8 (Other Payer Claim Control Number) omitted.
            // Segment NM1 (Other Payer Referring Provider) omitted.
            // Segment REF (Other Payer Referring Provider Secondary Identification) omitted.
            // Segment NM1 (Other Payer Rendering Provider) omitted.
            // Segment REF (Other Payer Rendering Provider Secondary Identification) omitted.
            // Segment NM1 (Other Payer Service Facility Location) omitted.
            // Segment REF (Other Payer Service Facility Location Secondary Identification) omitted.
            // Segment NM1 (Other Payer Supervising Provider) omitted.
            // Segment REF (Other Payer Supervising Provider Secondary Identification) omitted.
            // Segment NM1 (Other Payer Billing Provider) omitted.
            // Segment REF (Other Payer Billing Provider Secondary Identification) omitted.
        } // End loops 2320/2330*.

        $loopcount = 0;

        // Loop 2400 Procedure Loop.
        //

        for ($prockey = 0; $prockey < $proccount; ++$prockey) {
            ++$loopcount;
            ++$edicount;
            $out .= "LX" .      // Segment LX, Service Line. Page 398.
                "*" . $loopcount .
                "~\n";

                ++$edicount;

                // Build procedure code.
                $sv101 = "HC:" . $claim->cptKey($prockey);
                
                // Force AT modifier for Priority Health chiropractic manipulation.
                if (
                    trim((string)$claim->payerID()) === '38217' &&
                    in_array($claim->cptKey($prockey), ['98940', '98941', '98942'])
                ) {
                    $sv101 .= ":AT";
                }
                
                $out .= "SV1"
                    . "*" . $sv101;
                
                // Description for NOC items.
                if ($claim->cptNOC($prockey)) {
                    $out .= ":::::" . $claim->cptDescription($prockey);
                }
                
                // Build diagnosis pointers based on HI ($da)
                $diagPointers = '';
                $maxPointers = min(4, count($da));
                
                for ($i = 0; $i < $maxPointers; $i++) {
                    if ($i) {
                        $diagPointers .= ':';
                    }
                    $diagPointers .= ($i + 1);
                }
                
                $out .= "*" . sprintf('%.2f', $claim->cptCharges($prockey))
                    . "*" . "UN"
                    . "*" . $claim->cptUnits($prockey);

            // ONLY add diagnosis pointers if they exist
            if ($diagPointers !== '') {
                $out .= "***" . $diagPointers;
            }

            $out .= "~\n";
            
        

            if (!$claim->cptCharges($prockey)) {
                $log .= "*** Procedure '" . $claim->cptKey($prockey) . "' has no charges!\n";
            }

            if ($diagPointers === '') {
                $log .= "*** Procedure '" . $claim->cptKey($prockey) . "' is not justified!\n";
            }

            // Segment SV5 (Durable Medical Equipment Service) omitted.
            // Segment PWK01 (Line Supplemental Information) omitted.
            // Segment CR1 (Ambulance Transport Information) omitted.
            // Segment CR3 (Durable Medical Equipment Certification) omitted.
            // Segment CRC (Ambulance Certification) omitted.
            // Segment CRC (Hospice Employee Indicator) omitted.
            // Segment CRC (Condition Indicator / Durable Medical Equipment) omitted.

            ++$edicount;
            $out .= "DTP" .     // Date of Service. Page 435.
                "*" . "472" .
                "*" . "D8" .
                "*" . $claim->serviceDate() .
                "~\n";

            $testnote = rtrim($claim->cptNotecodes($prockey));
            if (!empty($testnote)) {
                ++$edicount;
                $out .= "NTE" .     // Explain Unusual Circumstances.
                    "*" . "ADD" .
                    "*" . $claim->cptNotecodes($prockey) .
                    "~\n";
            }

            // Segment DTP*471 (Prescription Date) omitted.
            // Segment DTP*607 (Revision/Recertification Date) omitted.
            // Segment DTP*463 (Begin Therapy Date) omitted.
            // Segment DTP*461 (Last Certification Date) omitted.
            // Segment DTP*304 (Last Seen Date) omitted.
            // Segment DTP (Test Date) omitted.
            // Segment DTP*011 (Shipped Date) omitted.
            // Segment DTP*455 (Last X-Ray Date) omitted.
            // Segment DTP*454 (Initial Treatment Date) omitted.
            // Segment QTY (Ambulance Patient Count) omitted.
            // Segment QTY (Obstetric Anesthesia Additional Units) omitted.
            // Segment MEA (Test Result) omitted.
            // Segment CN1 (Contract Information) omitted.
            // Segment REF*9B (Repriced Line Item Reference Number) omitted.
            // Segment REF*9D (Adjusted Repriced Line Item Reference Number) omitted.
            // Segment REF*G1 (Prior Authorization) omitted.
            // Segment REF*6R (Line Item Control Number) omitted.
            //   (Really oughta have this for robust 835 posting!)
            // Segment REF*EW (Mammography Certification Number) omitted.
            // Segment REF*X4 (CLIA Number) omitted.
            // Segment REF*F4 (Referring CLIA Facility Identification) omitted.
            // Segment REF*BT (Immunization Batch Number) omitted.
            // Segment REF*9F (Referral Number) omitted.
            // Segment AMT*T (Sales Tax Amount) omitted.
            // Segment AMT*F4 (Postage Claimed Amount) omitted.
            // Segment K3 (File Information) omitted.
            // Segment NTE (Line Note) omitted.
            // Segment NTE (Third Party Organization Notes) omitted.
            // Segment PS1 (Purchased Service Information) omitted.
            // Segment HCP (Line Pricing/Repricing Information) omitted.

            // Loop 2410, Drug Information. Medicaid insurers seem to want this
            // with HCPCS codes.
            //
            $ndc = $claim->cptNDCID($prockey);

            if ($ndc) {
                ++$edicount;
                $out .= "LIN" . // Drug Identification. Page 500+ (Addendum pg 71).
                    "*" .         // Per addendum, LIN01 is not used.
                    "*" . "N4" .
                    "*" . $ndc .
                    "~\n";

                if (!preg_match('/^\d\d\d\d\d-\d\d\d\d-\d\d$/', $ndc, $tmp) && !preg_match('/^\d{11}$/', $ndc)) {
                    $log .= "*** NDC code '$ndc' has invalid format!\n";
                }

                ++$edicount;
                $out .= "CTP" . // Drug Pricing. Page 500+ (Addendum pg 74).
                    "*" .
                    "*" .
                    "*" .
                    "*" . $claim->cptNDCQuantity($prockey) .
                    "*" . $claim->cptNDCUOM($prockey) .
                    // Note: 5010 documents "ME" (Milligrams) as an additional unit of measure.
                    "~\n";

                // Segment REF (Prescription or Compound Drug Association Number) omitted.
            }


            // Loop 2420A, Rendering Provider (service-specific).
            // Used if the rendering provider for this service line is different
            // from that in loop 2310B.

            if ($claim->providerNPI() != $claim->providerNPI($prockey)) {
                ++$edicount;
                $out .= "NM1" .       // Loop 2420A Rendering Provider
                    "*" . "82" .
                    "*" . "1" .
                    "*" . $claim->providerLastName($prockey) .
                    "*" . $claim->providerFirstName($prockey) .
                    "*" . $claim->providerMiddleName($prockey) .
                    "*" .
                    "*";
                if ($claim->providerNPI($prockey)) {
                    $out .=
                        "*" . "XX" .
                        "*" . $claim->providerNPI($prockey);
                } else {
                    $log .= "*** Rendering provider has no NPI.\n";
                }
                $out .= "~\n";

                // Segment PRV*PE (Rendering Provider Specialty Information) .

                if ($claim->providerTaxonomy($prockey)) {
                    ++$edicount;
                    $out .= "PRV" .
                        "*" . "PE" . // PErforming provider
                        "*" . "PXC" .
                        "*" . $claim->providerTaxonomy($prockey) .
                        "~\n";
                }

                // Segment REF (Rendering Provider Secondary Identification).
                // REF*1C is required here for the Medicare provider number if NPI was
                // specified in NM109.  Not sure if other payers require anything here.

                if ($claim->providerNumberType($prockey) == "G2") {
                    ++$edicount; $out .= "REF" . "*" . $claim->providerNumberType($prockey) .
                        "*" . $claim->providerNumber($prockey) . "~\n";
                }
            } // end provider exception

            // Segment NM1 (Loop 2420B Purchased Service Provider Name) omitted.
            // Segment REF (Loop 2420B Purchased Service Provider Secondary Identification) omitted.
            // Segment NM1,N3,N4 (Loop 2420C Service Facility Location) omitted.
            // Segment REF (Loop 2420C Service Facility Location Secondary Identification) omitted.
            // Segment NM1 (Loop 2420D Supervising Provider Name) omitted.
            // Segment REF (Loop 2420D Supervising Provider Secondary Identification) omitted.

            // Loop 2420E, Ordering Provider omitted.

            // Segment NM1 (Referring Provider Name) omitted.
            // Segment REF (Referring Provider Secondary Identification) omitted.
            // Segments NM1*PW, N3, N4 (Ambulance Pick-Up Location) omitted.
            // Segments NM1*45, N3, N4 (Ambulance Drop-Off Location) omitted.

            // Loop 2430, adjudication by previous payers.

            for ($ins = 1; $ins < $claim->payerCount(); ++$ins) {
                if ($claim->payerSequence($ins) > $claim->payerSequence()) {
                    continue; // payer is future, not previous
                }

                $payerpaid = $claim->payerTotals($ins, $claim->cptKey($prockey));
                $aarr = $claim->payerAdjustments($ins, $claim->cptKey($prockey));

                $hasRealAdj = false;

                if (!empty($aarr)) {
                    foreach ($aarr as $adj) {
                        if (!empty($adj[2]) && (float)$adj[2] != 0) {
                            $hasRealAdj = true;
                            break;
                        }
                    }
                }

                if ((float)$payerpaid[1] == 0.0 && !$hasRealAdj) {
                    continue;
                }

                // Format amount safely
                $paidAmt = ((float)$payerpaid[1] == 0.0)
                    ? '0'
                    : rtrim(rtrim(number_format((float)$payerpaid[1], 2, '.', ''), '0'), '.');

                // Send SVD if payment OR adjustments exist
                if ((float)$payerpaid[1] > 0.0 || $hasRealAdj) {
                    ++$edicount;
                    $out .= "SVD"
                        . "*" . $claim->payerID($ins)
                        . "*" . $paidAmt
                        . "*" . "HC:" . $claim->cptKey($prockey)
                        . "*"
                        . "*" . $claim->cptUnits($prockey)
                        . "~\n";
                }

                $tmpdate = $payerpaid[0];
                $cas = $claim->getLineItemAdjustments($aarr);

                // $key is the group code or payer_paid_date
                foreach ($cas as $key => $value) {
                    if ($key == 'payer_paid_date') {
                        if (!$tmpdate) {
                            $tmpdate = $value;
                        }
                        continue;
                    }

                    $out .= "CAS" .
                        "*" .
                        $key . "*";
                    $size = count($value);
                    $cntr = 0;
                    // $k is the reason code
                    // $v is the amount
                    foreach ($value as $k => $v) {
                        $cntr++;
                        $out .= $k .
                            "*" .
                            $v;
                        if ($cntr < $size) {
                            $out .= "*" .
                                "*";
                        }
                    }
                    $out .= "~\n";
                    ++$edicount;
                }

                if ($tmpdate) {
                    ++$edicount;
                    $out .= "DTP" . // Previous payer's line adjustment date. Page 493/566.
                        "*" . "573" .
                        "*" . "D8" .
                        "*" . $tmpdate .
                        "~\n";
                }

                // Segment AMT*EAF (Remaining Patient Liability) omitted.
                // Segment LQ (Form Identification Code) omitted.
                // Segment FRM (Supporting Documentation) omitted.
            } // end loop 2430
        } // end this procedure

        if (
            $SEFLAG == true
            || empty($GLOBALS['gen_x12_based_on_ins_co'])
        ) {
            //++$edicount; todo: This might have to go into the SE flag spot //***MS Modify

            $out .= "SE"
            . "*" . ($edicount + 1)
            . "*" . "0021"
            . "~\n";
        }

        $out .= "GE" .        // GE Trailer
            "*" . "1" .
            "*" . "1" .
            "~\n";

        $out .= "IEA" .       // IEA Trailer
            "*" . "1" .
            "*" . "000000001" .
            "~\n";

        // Remove any trailing empty fields (delimiters) from each segment.
        $out = preg_replace('/\*{2,}~/', '*~', $out);

        $log .= "\n";
        return $out;
    }

    
}
