<?php

declare(strict_types=1);

namespace App\Domain\FP;

enum AuditEventType: string
{
    case Submitted = 'SUBMITTED';
    case BiodataChanged = 'BIODATA_CHANGED';
    case BiodataVerified = 'BIODATA_VERIFIED';
    case DocumentsVerified = 'DOCUMENTS_VERIFIED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Exited = 'EXITED';
    case TeamChanged = 'TEAM_CHANGED';
    case DocumentChanged = 'DOCUMENT_CHANGED';
}
