<?php

/**
 * @file plugins/generic/cspWorkflow/classes/publication/CspPublicationRepository.php
 *
 * Copyright (c) 2020-2026 Lívia Gouvêa
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CspPublicationRepository
 * @brief Bypasses the abstract word-count limit check for editorial staff
 *  (Journal Manager, Section Editor, Assistant, Site Admin).
 */

namespace APP\plugins\generic\cspWorkflow\classes\publication;

use APP\core\Application;
use APP\submission\Submission;
use PKP\context\Context;
use PKP\security\Role;

class CspPublicationRepository extends \APP\publication\Repository
{
    private const EDITORIAL_ROLES = [
        Role::ROLE_ID_SITE_ADMIN,
        Role::ROLE_ID_MANAGER,
        Role::ROLE_ID_SUB_EDITOR,
        Role::ROLE_ID_ASSISTANT,
    ];

    /** @copydoc \APP\publication\Repository::validate() */
    public function validate($publication, array $props, Submission $submission, Context $context): array
    {
        $errors = parent::validate($publication, $props, $submission, $context);

        if (empty($errors['abstract'])) {
            return $errors;
        }

        // Bypass word-count for editorial staff only.
        $user = Application::get()->getRequest()->getUser();
        if (!$user || !$user->hasRole(self::EDITORIAL_ROLES, $context->getId())) {
            return $errors;
        }

        foreach (array_keys($errors['abstract']) as $localeKey) {
            // A non-empty abstract that still has an error can only be the
            // word-count violation: the "required" error is only ever set
            // when the abstract for that locale is empty.
            if (!empty($props['abstract'][$localeKey])) {
                unset($errors['abstract'][$localeKey]);
            }
        }
        if (empty($errors['abstract'])) {
            unset($errors['abstract']);
        }

        return $errors;
    }
}
