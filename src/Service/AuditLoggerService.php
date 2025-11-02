<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Document;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Audit Logger Service
 *
 * Centralized service for logging all user and system actions.
 * Automatically captures request context (IP, User-Agent) when available.
 */
class AuditLoggerService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack
    ) {
    }

    /**
     * Log document view action
     */
    public function logDocumentView(User $user, Document $document): void
    {
        $log = AuditLog::createForDocument('view', $user, $document,
            sprintf('User viewed document: %s', $document->getOriginalName())
        );

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log document upload action
     */
    public function logDocumentUpload(User $user, Document $document): void
    {
        $log = AuditLog::createForDocument('upload', $user, $document,
            sprintf('User uploaded document: %s (%s bytes)',
                $document->getOriginalName(),
                $document->getFileSize()
            )
        );

        $log->addMetadata('file_size', $document->getFileSize());
        $log->addMetadata('mime_type', $document->getMimeType());

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log document update action
     */
    public function logDocumentUpdate(User $user, Document $document, array $changes = []): void
    {
        $log = AuditLog::createForDocument('update', $user, $document,
            sprintf('User updated document: %s', $document->getOriginalName())
        );

        if (!empty($changes)) {
            $log->addMetadata('changes', $changes);
        }

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log document delete action
     */
    public function logDocumentDelete(User $user, Document $document): void
    {
        $log = AuditLog::createForDocument('delete', $user, $document,
            sprintf('User deleted document: %s', $document->getOriginalName()),
            'warning'
        );

        $log->addMetadata('file_path', $document->getFilePath());
        $log->addMetadata('file_size', $document->getFileSize());

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log document download action
     */
    public function logDocumentDownload(User $user, Document $document): void
    {
        $log = AuditLog::createForDocument('download', $user, $document,
            sprintf('User downloaded document: %s', $document->getOriginalName())
        );

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log document share action
     */
    public function logDocumentShare(User $user, Document $document, User $sharedWith, string $permissionLevel): void
    {
        $log = AuditLog::createForDocument('share', $user, $document,
            sprintf('User shared document %s with %s (%s access)',
                $document->getOriginalName(),
                $sharedWith->getEmail(),
                $permissionLevel
            )
        );

        $log->addMetadata('shared_with_email', $sharedWith->getEmail());
        $log->addMetadata('shared_with_id', $sharedWith->getId());
        $log->addMetadata('permission_level', $permissionLevel);

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log user login action
     */
    public function logUserLogin(User $user, bool $success = true): void
    {
        $log = AuditLog::createForUser('login', $user, $user,
            sprintf('User %s logged in successfully', $user->getEmail())
        );

        $log->addMetadata('success', $success);

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log user logout action
     */
    public function logUserLogout(User $user): void
    {
        $log = AuditLog::createForUser('logout', $user, $user,
            sprintf('User %s logged out', $user->getEmail())
        );

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log failed login attempt
     */
    public function logFailedLogin(string $email, ?string $reason = null): void
    {
        $log = new AuditLog();
        $log->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $log->setAction('user.login_failed');
        $log->setResource('User');
        $log->setDescription(sprintf('Failed login attempt for: %s', $email));
        $log->setLevel('warning');
        $log->addMetadata('email', $email);

        if ($reason) {
            $log->addMetadata('reason', $reason);
        }

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log user created action
     */
    public function logUserCreated(User $admin, User $newUser): void
    {
        $log = AuditLog::createForUser('created', $admin, $newUser,
            sprintf('Admin created user: %s', $newUser->getEmail())
        );

        $log->addMetadata('new_user_email', $newUser->getEmail());
        $log->addMetadata('new_user_roles', $newUser->getRoles());

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log user updated action
     */
    public function logUserUpdated(User $admin, User $targetUser, array $changes = []): void
    {
        $log = AuditLog::createForUser('updated', $admin, $targetUser,
            sprintf('Admin updated user: %s', $targetUser->getEmail())
        );

        if (!empty($changes)) {
            $log->addMetadata('changes', $changes);
        }

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log user deleted action
     */
    public function logUserDeleted(User $admin, string $deletedUserEmail, string $deletedUserId): void
    {
        $log = new AuditLog();
        $log->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $log->setAction('user.deleted');
        $log->setResource('User');
        $log->setResourceId($deletedUserId);
        $log->setUser($admin);
        $log->setDescription(sprintf('Admin deleted user: %s', $deletedUserEmail));
        $log->setLevel('warning');
        $log->addMetadata('deleted_user_email', $deletedUserEmail);

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log group created action
     */
    public function logGroupCreated(User $user, string $groupName, ?string $groupId = null): void
    {
        $log = new AuditLog();
        $log->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $log->setAction('group.created');
        $log->setResource('UserGroup');

        if ($groupId) {
            $log->setResourceId($groupId);
        }

        $log->setUser($user);
        $log->setDescription(sprintf('User created group: %s', $groupName));
        $log->addMetadata('group_name', $groupName);

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log user added to group action
     */
    public function logUserAddedToGroup(User $admin, User $targetUser, string $groupName): void
    {
        $log = new AuditLog();
        $log->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $log->setAction('group.user_added');
        $log->setResource('UserGroup');
        $log->setUser($admin);
        $log->setDescription(sprintf('Added user %s to group: %s', $targetUser->getEmail(), $groupName));
        $log->addMetadata('target_user_email', $targetUser->getEmail());
        $log->addMetadata('group_name', $groupName);

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log user removed from group action
     */
    public function logUserRemovedFromGroup(User $admin, User $targetUser, string $groupName): void
    {
        $log = new AuditLog();
        $log->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $log->setAction('group.user_removed');
        $log->setResource('UserGroup');
        $log->setUser($admin);
        $log->setDescription(sprintf('Removed user %s from group: %s', $targetUser->getEmail(), $groupName));
        $log->addMetadata('target_user_email', $targetUser->getEmail());
        $log->addMetadata('group_name', $groupName);

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Log system event
     */
    public function logSystemEvent(string $eventName, ?string $description = null, string $level = 'info'): void
    {
        $log = AuditLog::createSystemLog($eventName, $description, $level);
        $log->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Generic log method for custom actions
     */
    public function log(
        string $action,
        ?User $user,
        string $resource,
        ?string $resourceId = null,
        ?string $description = null,
        string $level = 'info',
        array $metadata = []
    ): void {
        $log = new AuditLog();
        $log->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $log->setAction($action);
        $log->setResource($resource);
        $log->setResourceId($resourceId);
        $log->setUser($user);
        $log->setDescription($description);
        $log->setLevel($level);
        $log->setMetadata($metadata);

        $this->addRequestContext($log);
        $this->persist($log);
    }

    /**
     * Add request context (IP address, User-Agent) to log
     */
    private function addRequestContext(AuditLog $log): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request) {
            $log->setIpAddress($request->getClientIp());
            $log->setUserAgent($request->headers->get('User-Agent'));
        }
    }

    /**
     * Persist and flush the audit log
     */
    private function persist(AuditLog $log): void
    {
        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
