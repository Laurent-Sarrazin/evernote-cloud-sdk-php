<?php

namespace Evernote;

use EDAM\Error\EDAMNotFoundException;
use EDAM\Error\EDAMUserException;
use EDAM\NoteStore\NoteFilter;
use EDAM\NoteStore\NotesMetadataResultSpec;
use EDAM\Types\LinkedNotebook;
use Evernote\Exception\ExceptionFactory;
use Evernote\Exception\NotFoundNotebookException;
use Evernote\Exception\NotFoundNoteException;
use Evernote\Exception\PermissionDeniedException;
use Evernote\Model\Note;
use Evernote\Model\Notebook;
use ohmy\Auth1;

class Client
{
    /** @var  \Evernote\AdvancedClient */
    protected $advancedClient;

    /** @var  string */
    protected $token;

    /** @var  boolean */
    protected $sandbox;

    /** @var  \EDAM\NoteStore\NoteStoreClient */
    protected $userNoteStore;

    /** @var  \EDAM\NoteStore\NoteStoreClient */
    protected $businessNoteStore;

    /** @var  string */
    protected $businessToken;

    /** @var   \EDAM\Types\User */
    protected $user;

    /** @var  \EDAM\UserStore\AuthenticationResult */
    protected $businessAuth;

    const PERSONAL_SCOPE = 1;

    const LINKED_SCOPE   = 2;

    /* Search scopes */

    /**
     *  Only used if specifying an explicit notebook instead.
     */
    const SEARCH_SCOPE_NONE            = 0;

    /**
     *  Search among all personal notebooks.
     */
    const SEARCH_SCOPE_PERSONAL        = 1;

    /**
     *  Search among all notebooks shared to the user by others.
     */
    const SEARCH_SCOPE_PERSONAL_LINKED = 2;

    /**
     *  Search among all business notebooks the user has joined.
     */
    const SEARCH_SCOPE_BUSINESS        = 3;

    /**
     *  Use this if your app uses an "App Notebook". (any other set flags will be ignored.)
     */
    const SEARCH_SCOPE_APPNOTEBOOK     = 4;

    /*****************/

    public function __construct($token = null, $sandbox = true, $advancedClient = null)
    {
        $this->token   = $token;
        $this->sandbox = $sandbox;

        if (null === $advancedClient) {
            $advancedClient = new AdvancedClient($this->sandbox);
        }

        $this->advancedClient = $advancedClient;
    }

    public function getUser()
    {
        if (null === $this->user) {
            try {
                $this->user = $this->getAdvancedClient()
                    ->getUserStore()->getUser($this->token);
            } catch (\Exception $e) {
                throw ExceptionFactory::create($e);
            }
        }

        return $this->user;
    }

    public function isBusinessUser()
    {
        return $this->getUser()->accounting->businessId !== null;
    }

    public function getBusinessAuth()
    {
        if (null === $this->businessAuth) {
            try {
                $this->businessAuth =
                    $this->getAdvancedClient()->getUserStore()->authenticateToBusiness($this->token);
            } catch (\Exception $e) {
                throw ExceptionFactory::create($e);
            }
        }

        return $this->businessAuth;
    }

    public function getBusinessToken()
    {
        if (null === $this->businessToken) {
            $this->businessToken = $this->getBusinessAuth()->authenticationToken;
        }

        return $this->businessToken;
    }
    
    public function getBusinessNoteStore()
    {
        if (false === $this->isBusinessUser()) {
            throw new PermissionDeniedException('Business');
        }

        if (null === $this->businessNoteStore) {
            $this->businessNoteStore =
                $this->getNoteStore($this->getBusinessAuth()->noteStoreUrl);
        }

        return $this->businessNoteStore;
    }

    public function getBusinessSharedNotebooks()
    {
        try {
            return $this->getBusinessNoteStore()->listSharedNotebooks($this->getBusinessToken());
        } catch (\Exception $e) {
            throw ExceptionFactory::create($e);
        }

    }

    public function getBusinessLinkedNotebooks()
    {
        try {
            return $this->getBusinessNoteStore()->listNotebooks($this->getBusinessToken());
        } catch (\Exception $e) {
            throw ExceptionFactory::create($e);
        }
    }

    public function listNotebooks()
    {
        /**
         * 1. Get all of the user's personal notebooks.
         */
        $personalNotebooks = $this->listPersonalNotebooks();

        $resultNotebooks  = array();
        $guidsToNotebooks = array();
        foreach($personalNotebooks as $personalNotebook) {
            $resultNotebook = new Notebook($personalNotebook);
            $resultNotebooks[] = $resultNotebook;
            $guidsToNotebooks[$personalNotebook->guid] = $resultNotebook;
        }

        /**
         * Get shared notebooks and flag the matching notebook as shared
         */
        $sharedNotebooks = $this->listSharedNotebooks();

        foreach ($sharedNotebooks as $sharedNotebook) {
            $guidsToNotebooks[$sharedNotebook->notebookGuid]->isShared = true;
        }

        /**
         * 2. Get all of the user's linked notebooks. These will include business and/or shared notebooks.
         */

        try {
            $linkedNotebooks = $this->listLinkedNotebooks();
        } catch (EDAMUserException $e) {
            $linkedNotebooks = array();
        }

        if (count($linkedNotebooks) > 0) {
            /**
             * 3. Business user
             */
            if (null !== $this->getBusinessNoteStore()) {

                /**
                 * a. Get the business's shared notebooks. Some of these may match to personal linked notebooks.
                 *
                 */
                $businessSharedNotebooks     = $this->getBusinessSharedNotebooks();
                $sharedBusinessNotebookGuids = array();
                $sharedBusinessNotebooks     = array();
                foreach ($businessSharedNotebooks as $businessSharedNotebook) {
                    $sharedBusinessNotebooks[$businessSharedNotebook->shareKey] = $businessSharedNotebook;
                    $sharedBusinessNotebookGuids[] = $businessSharedNotebook->notebookGuid;
                }

                $guidsCount = array_count_values($sharedBusinessNotebookGuids);

                $businessNotebooksGuids = array();

                /**
                 * b. Get the business's linked notebooks. Some of these will match to shared notebooks in (a), providing a
                //      complete authorization story for the notebook.
                 */
                $businessNotebooks = $this->getBusinessLinkedNotebooks();

                foreach ($businessNotebooks as $businessNotebook) {
                    $businessNotebooksGuids[$businessNotebook->guid] = $businessNotebook;
                }

                foreach ($linkedNotebooks as $linkedNotebook) {
                    if (array_key_exists($linkedNotebook->shareKey, $sharedBusinessNotebooks)) {
                        $sharedNotebook = $sharedBusinessNotebooks[$linkedNotebook->shareKey];
                        $businessNotebook = $businessNotebooksGuids[$sharedNotebook->notebookGuid];

                        $result = new Notebook($businessNotebook, $linkedNotebook, $sharedNotebook, $businessNotebook);
                        if ((array_key_exists($sharedNotebook->notebookGuid, $guidsCount) && $guidsCount[$sharedNotebook->notebookGuid] > 1)
                            || $businessNotebook->businessNotebook !== null) {
                            $result->isShared = true;
                        }
                        $resultNotebooks[] = $result;
                    } else {
                        if (null === $linkedNotebook->shareKey) {
                            continue;
                        }
                        $resultNotebooks[] = $this->getNotebookByLinkedNotebook($linkedNotebook);
                    }
                }

            } else {
                foreach ($linkedNotebooks as $linkedNotebook) {
                    try {
                        $resultNotebooks[] = $this->getNotebookByLinkedNotebook($linkedNotebook);
                    } catch (\Exception $e) {
                    }
                };
            }
        }

        return $resultNotebooks;
    }

    protected function getSharedNotebookAuthResult(LinkedNotebook $linkedNotebook)
    {
        $sharedNoteStore = $this->getNotestore($linkedNotebook->noteStoreUrl);

        if (null === $linkedNotebook->shareKey) {
            throw new PermissionDeniedException('shareKey');
        }

        try {
            return $sharedNoteStore->authenticateToSharedNotebook($linkedNotebook->shareKey, $this->token);
        } catch (\Exception $e) {
            throw ExceptionFactory::create($e);
        }
    }

    protected function getNotebookByLinkedNotebook(LinkedNotebook $linkedNotebook)
    {
        $sharedNoteStore = $this->getNotestore($linkedNotebook->noteStoreUrl);
        $authToken = $this->getSharedNotebookAuthResult($linkedNotebook)->authenticationToken;
        try {
            $sharedNotebook = $sharedNoteStore->getSharedNotebookByAuth($authToken);
        } catch (\Exception $e) {
            throw ExceptionFactory::create($e);
        }

        $notebook = new Notebook(null, $linkedNotebook, $sharedNotebook);

        $notebook->authToken = $authToken;

        return $notebook;
    }
    
    
    public function listPersonalNotebooks()
    {
        try {
            return $this->getUserNotestore()->listNotebooks($this->token);
        } catch (\Exception $e) {
            throw ExceptionFactory::create($e);
        }
    }

    public function listSharedNotebooks()
    {
        try {
            return $this->getUserNotestore()->listSharedNotebooks($this->token);
        } catch (\Exception $e) {
            throw ExceptionFactory::create($e);
        }

    }

    public function listLinkedNotebooks()
    {
        try {
            return $this->getUserNotestore()->listLinkedNotebooks($this->token);
        } catch (\Exception $e) {
            throw ExceptionFactory::create($e);
        }
    }

    protected function getUserNotestore()
    {
        if (null === $this->userNoteStore) {
            try {
                $noteStoreUrl =
                    $this->getAdvancedClient()
                        ->getUserStore()
                        ->getNoteStoreUrl($this->token);
            } catch (\Exception $e) {
                throw ExceptionFactory::create($e);
            }
            $this->userNoteStore = $this->getNoteStore($noteStoreUrl);
        }

        return $this->userNoteStore;
    }

    protected function getNoteStore($noteStoreUrl)
    {
        return $this->getAdvancedClient()->getNoteStore($noteStoreUrl);
    }

    /**
     * @param \Evernote\AdvancedClient $advancedClient
     */
    public function setAdvancedClient($advancedClient)
    {
        $this->advancedClient = $advancedClient;
    }

    /**
     * @return \Evernote\AdvancedClient
     */
    public function getAdvancedClient()
    {
        return $this->advancedClient;
    }

    /**
     * @param boolean $sandbox
     */
    public function setSandbox($sandbox)
    {
        $this->sandbox = $sandbox;
    }

    /**
     * @return boolean
     */
    public function getSandbox()
    {
        return $this->sandbox;
    }

    /**
     * @param string $token
     */
    public function setToken($token)
    {
        $this->token = $token;
    }

    /**
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    public function replaceNote(Note $noteToReplace, Note $note)
    {
        $edamNote = $noteToReplace->getEdamNote();

        $edamNote->title      = $note->title;
        $edamNote->content    = $note->content;
        $edamNote->attributes = $note->attributes;
        $edamNote->resources  = $note->resources;

        try {
            $uploaded_note = $noteToReplace->noteStore->updateNote($noteToReplace->authToken, $edamNote);
        } catch (\Exception $e) {
            throw ExceptionFactory::create($e);
        }

        $uploaded_note->content = $note->content;

        $en_note = new Note($uploaded_note);

        $en_note->setSaved(true);

        return $en_note;
    }

    public function uploadNote(Note $note, Notebook $notebook = null)
    {
        if ($this->isAppNotebookToken($this->token)) {
            $notebook = new Notebook();
        }

        if (true === $note->getSaved()) {
            return $this->replaceNote($note, $note);
        }

        $edamNote = new \EDAM\Types\Note();

        $edamNote->title      = $note->title;
        $edamNote->content    = $note->content;
        $edamNote->attributes = $note->attributes;
        $edamNote->resources  = $note->resources;

        if (null !== $notebook && null !== $notebook->guid) {
            $edamNote->notebookGuid = $notebook->guid;
        }

        try {
            $uploaded_note = $this->getUserNotestore()->createNote($this->token, $edamNote);
            $noteStore     = $this->getUserNotestore();
            $token         = $this->token;
        } catch (EDAMNotFoundException $e) {
            $notebook = $this->getNotebook($notebook->guid, self::LINKED_SCOPE);
            if (null === $notebook) {
                throw ExceptionFactory::create($e);
            }

            if ($notebook->isLinkedNotebook()) {
                $noteStore = $this->getNotestore($notebook->linkedNotebook->noteStoreUrl);
                $token     = $notebook->authToken;

                $uploaded_note = $noteStore->createNote($token, $edamNote);
            }
        }
        catch (\Exception $e) {
            throw ExceptionFactory::create($e);
        }

        $uploaded_note->content = $note->content;

        $note = $this->getNoteInstance($uploaded_note, $noteStore, $token);

        $note->setSaved(true);

        return $note;
    }

    public function deleteNote(Note $note)
    {
        // we have the credentials
        if (null !== $note->noteStore && null !== $note->authToken) {
            try {
                $note->noteStore->deleteNote($note->authToken, $note->guid);
            } catch (\Exception $e) {
                throw ExceptionFactory::create($e);
            }

            return true;
        }

        try {
            // We try to delete it with the personal credentials
            $this->getUserNotestore()->deleteNote($this->token, $note->guid);

            return true;
        } catch (EDAMNotFoundException $e) {
            // The note's not in a personal notebook. We'll need to find it
            $note = $this->getNote($note->guid, self::LINKED_SCOPE);

            if (null !== $note) {
                return $this->deleteNote($note);
            }

            return false;
        } catch (\Exception $e) {
            throw ExceptionFactory::create($e);
        }
    }

    public function shareNote(Note $note)
    {
        // we have the credentials
        if (null !== $note->noteStore && null !== $note->authToken) {
            try {
                $shareKey = $note->noteStore->shareNote($note->authToken, $note->guid);
            } catch (\Exception $e) {
                throw ExceptionFactory::create($e);
            }
            $shardId  = $this->getShardIdFromToken($note->authToken);

            return $this->getShareUrl($note->guid, $shardId, $shareKey, $this->getAdvancedClient()->getEndpoint());
        }

        try {
            // We don't have credentials so we assume it's a personal note
            return $this->getUserNotestore()->shareNote($this->token, $note->guid);

        } catch (EDAMNotFoundException $e) {
            // The note's not in a personal notebook. We'll need to find it
            $note = $this->getNote($note->guid, self::LINKED_SCOPE);

            if (null !== $note) {
                $shareKey = $note->noteStore->shareNote($note->authToken, $note->guid);
                $shardId  = $this->getShardIdFromToken($note->authToken);

                return $this->getShareUrl($note->guid, $shardId, $shareKey, $this->getAdvancedClient()->getEndpoint());
            }

            return null;
        } catch (\Exception $e) {
            throw ExceptionFactory::create($e);
        }
    }

    public function getNote($guid, $scope = null)
    {
        if (null === $scope || self::PERSONAL_SCOPE === $scope) {
            try {
                $edam_note = $this->getUserNotestore()->getNote($this->token, $guid, true, true, false, false);

                return $this->getNoteInstance($edam_note, $this->getUserNotestore(), $this->token);
            } catch (NotFoundNoteException $e) {
                if (self::PERSONAL_SCOPE === $scope) {
                    return null;
                }
            } catch (\Exception $e) {
                throw ExceptionFactory::create($e);
            }
        }

        if (null === $scope || self::LINKED_SCOPE === $scope) {
            $linkedNotebooks = $this->listLinkedNotebooks();

            foreach ($linkedNotebooks as $linkedNotebook) {
                try {
                    $sharedNoteStore = $this->getNotestore($linkedNotebook->noteStoreUrl);
                    $authToken = $this->getSharedNotebookAuthResult($linkedNotebook)->authenticationToken;

                    $edam_note = $sharedNoteStore->getNote($authToken, $guid, true, true, false, false);

                    return $this->getNoteInstance($edam_note, $sharedNoteStore, $authToken);

                } catch (PermissionDeniedException $e) {
                    // No right on this notebook.
                    continue;
                } catch (NotFoundNoteException $e) {
                    // Note not found
                    continue;
                } catch (\Exception $e) {
                    throw ExceptionFactory::create($e);
                }

            }

            return null;
        }

    }

    protected function getShareUrl($guid, $shardId, $shareKey, $serviceHost)
    {
        return $serviceHost . "/shard/" . $shardId . "/sh/" . $guid . "/" . $shareKey;
    }

    public function isAppNotebookToken($token)
    {
        return strpos($token, ':B=') !== false;
    }

    public function getNotebook($notebook_guid, $scope = null)
    {
        if (null === $scope || self::PERSONAL_SCOPE === $scope) {
            try {
                $edamNotebook = $this->getUserNotestore()->getNotebook($this->token, $notebook_guid);

                return new Notebook($edamNotebook);
            } catch (NotFoundNotebookException $e) {
                if (self::PERSONAL_SCOPE === $scope) {
                    return null;
                }
            } catch (\Exeption $e) {
                throw ExceptionFactory::create($e);
            }
        }

        if (null === $scope || self::LINKED_SCOPE === $scope) {
            $linkedNotebooks = $this->listLinkedNotebooks();

            foreach ($linkedNotebooks as $linkedNotebook) {
                try {
                    $sharedNotebook = $this->getNotebookByLinkedNotebook($linkedNotebook);
                } catch (PermissionDeniedException $e) {
                    // No right on this notebook.
                    continue;
                }
                if ($sharedNotebook->guid === $notebook_guid) {
                    return $sharedNotebook;
                }
            }

            return null;
        }
    }

    public function getDefaultNotebook()
    {
        return new Notebook($this->getUserNotestore()->getDefaultNotebook($this->token));
    }

    protected function getNoteInstance(\EDAM\Types\Note $edamNote = null, $noteStore = null, $token = null)
    {
        $note = new Note($edamNote);

        $note->authToken = $token;

        $note->noteStore = $noteStore;

        return $note;
    }

    protected function getShardIdFromToken($token)
    {
        $result = preg_match('/:?S=(s[0-9]+):?/', $token, $matches);

        if ($result === 1 && array_key_exists(1, $matches)) {
            return $matches[1];
        }

        return null;
    }
}