import axios from 'axios';

/** Store a picture for an image shape. Resolves to { url, width, height }. */
export const uploadDiagramImage = async (file) => {
    const form = new FormData();
    form.append('image', file);
    const { data } = await axios.post(route('diagrams.images'), form);
    return data;
};

/** Read a picked .drawio file as text. */
export const readFileText = (file) => (typeof file.text === 'function'
    ? file.text()
    : new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsText(file);
    }));
