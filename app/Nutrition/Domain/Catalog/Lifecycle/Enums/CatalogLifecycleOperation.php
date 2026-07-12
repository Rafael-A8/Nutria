<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\Enums;

enum CatalogLifecycleOperation: string
{
    case CreateSource = 'create_source';
    case EditSource = 'edit_source';
    case CreateReference = 'create_reference';
    case CreateDraft = 'create_draft';
    case EditDraft = 'edit_draft';
    case SubmitForReview = 'submit_for_review';
    case ReturnToDraft = 'return_to_draft';
    case Approve = 'approve';
    case Reject = 'reject';
    case Publish = 'publish';
    case Activate = 'activate';
    case Reactivate = 'reactivate';
    case Deactivate = 'deactivate';
    case Withdraw = 'withdraw';
    case Archive = 'archive';
    case CreateSuccessor = 'create_successor';
    case ChangeAuthority = 'change_authority';
}
