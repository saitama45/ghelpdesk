import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';

/**
 * Autosave + live updates for a scene document (lib/sceneDocument) — shared by the task-board
 * whiteboard and diagrams.
 *
 * - Every local edit schedules a debounced PUT of the whole scene with the revision it was
 *   based on. A 409 means someone saved first: the document rebases its unsaved edits onto
 *   their scene and saves again.
 * - While visible, the page polls the state endpoint (cheap: it only returns the scene when
 *   the revision moved) and replaces the scene when nothing local is pending.
 * - Leaving the page flushes the last edits; closing the tab with unsaved edits warns.
 */
export function useSceneSync({
    doc,
    editable,
    initialRevision = 0,
    initialSavedAt = null,
    initialSavedBy = null,
    saveUrl,
    stateUrl,
    savePayload = () => ({}),
    pollParams = () => ({}),
    onPoll = () => {},
    onRemoteScene = () => {},
    onError = () => {},
    pollMs = 4000,
}) {
    const revision = ref(initialRevision);
    const lastSavedAt = ref(initialSavedAt);
    const lastSavedBy = ref(initialSavedBy);
    const saveState = ref('saved'); // saved | pending | saving | error
    const saveError = ref('');

    let saving = false;
    let saveAgain = false;
    let saveTimer = null;
    let conflictStreak = 0;
    let pollTimer = null;
    let polling = false;

    const scheduleSave = (delay = 900) => {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(save, delay);
    };

    async function save() {
        if (!editable.value) return;
        if (saving) {
            saveAgain = true;
            return;
        }
        if (!doc.isDirty()) {
            saveState.value = 'saved';
            return;
        }

        saving = true;
        saveState.value = 'saving';
        const inflight = doc.takeDirty();

        try {
            const { data } = await axios.put(saveUrl(), { revision: revision.value, scene: doc.toJSON(), ...savePayload() });
            revision.value = data.revision;
            lastSavedAt.value = data.updated_at;
            lastSavedBy.value = data.updated_by;
            saveError.value = '';
            conflictStreak = 0;
            saveState.value = doc.isDirty() ? 'pending' : 'saved';
        } catch (error) {
            doc.restoreDirty(inflight);
            const status = error.response?.status;

            if (status === 409 && error.response.data && conflictStreak < 5) {
                conflictStreak++;
                doc.rebase(error.response.data.scene);
                revision.value = error.response.data.revision;
                lastSavedBy.value = error.response.data.updated_by;
                saveAgain = true;
            } else if (status === 403) {
                editable.value = false;
                saveState.value = 'error';
                saveError.value = 'You can no longer edit this.';
            } else if (status === 422) {
                saveState.value = 'error';
                saveError.value = error.response.data?.message || 'This could not be saved.';
                onError(saveError.value);
            } else {
                saveState.value = 'error';
                saveError.value = 'Couldn’t save — retrying…';
                scheduleSave(5000);
            }
        } finally {
            saving = false;
            if (saveAgain) {
                saveAgain = false;
                scheduleSave(150);
            }
        }
    }

    watch(doc.changeCount, () => {
        if (!editable.value) return;
        saveState.value = 'pending';
        scheduleSave();
    });

    const poll = async () => {
        if (polling || document.visibilityState !== 'visible') return;
        polling = true;
        try {
            const { data } = await axios.get(stateUrl(), { params: { revision: revision.value, ...pollParams() } });
            onPoll(data);
            if (!data.changed) return;

            if (saving || doc.isDirty()) {
                // Our pending save will hit 409 and merge their scene with our edits.
                scheduleSave(0);
                return;
            }

            doc.setScene(data.scene);
            revision.value = data.revision;
            lastSavedAt.value = data.updated_at;
            lastSavedBy.value = data.updated_by;
            onRemoteScene(data);
        } catch {
            // Background refresh — stay quiet; the next poll retries.
        } finally {
            polling = false;
        }
    };

    const onBeforeUnload = (event) => {
        if (editable.value && (saving || doc.isDirty())) {
            event.preventDefault();
            event.returnValue = '';
        }
    };

    const onVisibility = () => {
        if (document.visibilityState === 'visible') poll();
    };

    onMounted(() => {
        pollTimer = setInterval(poll, pollMs);
        window.addEventListener('beforeunload', onBeforeUnload);
        document.addEventListener('visibilitychange', onVisibility);
    });

    onBeforeUnmount(() => {
        clearInterval(pollTimer);
        clearTimeout(saveTimer);
        window.removeEventListener('beforeunload', onBeforeUnload);
        document.removeEventListener('visibilitychange', onVisibility);
        // Leaving through an Inertia link: flush the last edits (the request outlives the page).
        if (editable.value && doc.isDirty()) save();
    });

    return {
        revision,
        lastSavedAt,
        lastSavedBy,
        saveState,
        saveError,
        save,
        scheduleSave,
        poll,
        isBusy: () => saving || doc.isDirty(),
    };
}
