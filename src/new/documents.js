// The Documents tab's "Download all shared files (ZIP)": Nextcloud's own ZIP
// of the project's Shared Files folder, the folder the old interface's
// Download ZIP gave. The files API names the folder "/{user}/files/…"; WebDAV
// wants "/files/{user}/…", as ProjectFilesBrowser's normalizedDavPath does.
export function sharedZipUrl(davRoot, sharedRoots) {
	const root = Array.isArray(sharedRoots) && sharedRoots.length === 1 ? sharedRoots[0] : null
	const path = typeof root?.path === 'string' ? root.path : ''
	const parts = path.split('/')
	if (parts.length < 4 || parts[0] !== '' || parts[2] !== 'files' || !parts[1]) return ''
	;[parts[1], parts[2]] = [parts[2], parts[1]]
	return String(davRoot).replace(/\/+$/, '') + parts.map(part => encodeURIComponent(part)).join('/') + '?accept=zip'
}
