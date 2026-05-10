// Mirrors App\Support\Format on the client.

export function createdDate(ms) {
    if (!ms) return '';
    try {
        const d = new Date(Number(ms));
        if (Number.isNaN(d.getTime())) return '';
        return d.toISOString().slice(0, 10);
    } catch {
        return '';
    }
}

export function years(minBirth, maxBirth, death) {
    if (!minBirth && !maxBirth && !death) return '';
    let birth;
    if (minBirth && maxBirth && minBirth !== maxBirth) {
        birth = `${minBirth}/${maxBirth}`;
    } else {
        birth = String(minBirth || maxBirth || '?');
    }
    return `(${birth}-${death || '?'})`;
}

export function displayLabel(personName, dnaName) {
    if (personName) return personName;
    if (dnaName) return `[${dnaName}]`;
    return '(UNKNOWN)';
}

export function formatCm(cm) {
    if (cm == null || cm === '') return '';
    return `${cm} cM`;
}
