<?php

namespace Pronote;

/**
 * Représente une période de l'année
 */
class Period extends DataClass
{
    protected Pronote $client;
    protected $id;
    public $name;
    public $start;
    public $end;

    /**
     * Récupère les notes de l'élève pour la période
     * @return array La liste des notes
     */
    public function grades()
    {
        $data = $this->getGrades();

        $grades = [];
        foreach ($data['listeDevoirs']['V'] as $grade) {
            $grades[] = [
                'title' => $grade['commentaire'],
                'value' => $this->gradeParse($grade['note']['V']),
                'scale' => $grade['bareme']['V'],
                'subject' => $grade['service']['V']['L'],
                'average' => $this->gradeParse($grade['moyenne']['V']),
                'max' => $grade['noteMax']['V'],
                'min' => $grade['noteMin']['V'],
                'coefficient' => $grade['coefficient'],
                'date' => $grade['date']['V'],
                'isBonus' => $grade['estBonus'],
                'isOptionnal' => $grade['estFacultatif'],
                'isScaledTo20' => $grade['estRamenerSur20']
            ];
        }

        return $grades;
    }

    /**
     * Récupère les notes de l'élève pour la période et par matière
     * @return array La liste des notes de la période par matière
     */
    public function gradesBySubject()
    {
        $data = $this->getGrades();

        $grades = [];
        foreach ($data['listeServices']['V'] as $subject) {
            $grades['subjects'][] = [
                'name' => $subject['L'],
                'average' => [
                    'student' => $this->gradeParse($subject['moyEleve']['V']),
                    'class' => $this->gradeParse($subject['moyClasse']['V']),
                    'min' => $subject['moyMin']['V'],
                    'max' => $subject['moyMax']['V'],
                    'scale' => $subject['baremeMoyEleve']['V'],
                ],
                'color' => $subject['couleur'],
                'grades' => []
            ];
        }

        foreach ($data['listeDevoirs']['V'] as $grade) {
            $subjectName = $grade['service']['V']['L'];

            // On trouve la clé du sujet dans l'array des matières
            $key = array_search($subjectName, array_column($grades['subjects'], 'name'));

            $grades['subjects'][$key]['grades'][] = [
                'title' => $grade['commentaire'],
                'value' => $this->gradeParse($grade['note']['V']),
                'scale' => $grade['bareme']['V'],
                'average' => $this->gradeParse($grade['moyenne']['V']),
                'max' => $grade['noteMax']['V'],
                'min' => $grade['noteMin']['V'],
                'coefficient' => $grade['coefficient'],
                'date' => $grade['date']['V'],
                'isBonus' => $grade['estBonus'],
                'isOptionnal' => $grade['estFacultatif'],
                'isScaledTo20' => $grade['estRamenerSur20']
            ];
        }
        return $grades;
    }

    /**
     * Récupère la moyenne générale de l'élève et de la classe pour la période
     * @return array La moyenne de l'élève et de la classe
     */
    public function overallAverage()
    {
        $data = $this->getGrades();
        return [
            'student' => $data['moyGenerale']['V'],
            'class' => $data['moyGeneraleClasse']['V'],
            'scale' => $data['baremeMoyGenerale']['V']
        ];
    }

    /**
     * Récupère les données de la page des notes sans les formattées
     */
    private function getGrades()
    {
        $response = $this->client->makeRequest('DernieresNotes', [
            'donnees' => [
                'Periode' => [
                    'N' => $this->id,
                    'L' => $this->name
                ]
            ],
            '_Signature_' => [
                'onglet' => 198
            ]
        ]);

        return $response['donneesSec']['donnees'];
    }

    /**
     * Fonction qui transforme les notes du type |number par leur valeur réelle
     * @param string $value La valeur de la note
     * @return string La note transformée ou pas
     */
    private function gradeParse(string $value)
    {
        $gradeTranslate = [
            'Absent',
            'Dispense',
            'NonNote',
            'Inapte',
            'NonRendu',
            'AbsentZero',
            'NonRenduZero',
            'Felicitations'
        ];

        if ($value[0] == '|') {
            return $gradeTranslate[$value[1] - 1];
        }

        return $value;
    }

    /**
     * Récupère les absences de l'élève pour la période
     * @return array La liste des absences
     */
    public function absences()
    {
        $data = $this->getPagePresence();
        
        $absences = [];

        // On parcourt les données renvoyées
        foreach ($data as $absence) {
            if ($absence['G'] != 13) continue;
            
            $reasons = [];
            foreach ($absence['listeMotifs']['V'] as $reason) {
                $reasons[] = $reason['L'];
            }

            $absences[] = [
                'from' => $absence['dateDebut']['V'],
                'to' => $absence['dateFin']['V'],
                'isJustified' => $absence['justifie'],
                'hoursMissed' => $absence['NbrHeures'],
                'daysMissed' => $absence['NbrJours'],
                'reasons' => $reasons
            ];
        }

        return $absences;
    }

    /**
     * Récupère les retards de l'élève pour la période
     * @return array La liste des retards
     */
    public function delays()
    {
        $data = $this->getPagePresence();

        $delays = [];

        // On parcourt les données renvoyées
        foreach ($data as $delay) {
            if ($delay['G'] != 14) continue;
            
            $reasons = [];
            foreach ($delay['listeMotifs']['V'] as $reason) {
                $reasons[] = $reason['L'];
            }

            $delays[] = [
                'date' => $delay['date']['V'],
                'minutesMissed' => $delay['duree'],
                'justified' => $delay['justifie'],
                'justification' => $delay['justification'],
                'reasons' => $reasons
            ];
        }

        return $delays;
    }

    /**
     * Récupère les punitions de l'élève pour la période
     */
    public function punishments()
    {
        $data = $this->getPagePresence();

        $punishments = [];

        // On parcourt les données renvoyées
        foreach ($data as $punishment) {
            if ($punishment['G'] != 41) continue;
            
            $reasons = [];
            foreach ($punishment['listeMotifs']['V'] as $reason) {
                $reasons[] = $reason['L'];
            }

            $schedule = [];
            foreach ($punishment['programmation']['V'] as $date) {
                $schedule[] = $date['date']['V'];
            }

            $punishments[] = [
                'given' => $punishment['dateDemande']['V'],
                'isExclusion' => $punishment['estUneExclusion'],
                'isDuringLesson' => !$punishment['horsCours'],
                'homework' => $punishment['travailAFaire'],
                'homeworkDocuments' => $punishment['documentsTAF']['V'],
                'circonstances' => $punishment['circonstances'],
                'circonstancesDocuments' => $punishment['documentsCirconstances']['V'],
                'nature' => $punishment['nature']['V']['L'],
                'reasons' => $reasons,
                'giver' => $punishment['demandeur']['V']['L'],
                'isSchedulable' => $punishment['estProgrammable'],
                'schedule' => $schedule,
                'duration' => $punishment['duree']
            ];
        }

        return $punishments;
    }

    private function getPagePresence()
    {
        $response = $this->client->makeRequest('PagePresence', [
            'donnees' => [
                'periode' => [
                    'N' => $this->id,
                    'L' => $this->name
                ],
                'DateDebut' => [
                    '_T' => 7,
                    'V' => $this->start . ' 0:0:0'
                ],
                'DateFin' => [
                    '_T' => 7,
                    'V' => $this->end . ' 0:0:0'
                ]
            ],
            '_Signature_' => [
                'onglet' => 19
            ]
        ]);

        return $response['donneesSec']['donnees']['listeAbsences']['V'];
    }
}
