import { handleMedia } from '../../src/router.js';

export const onRequestGet = (context) => handleMedia(context);
export const onRequestHead = (context) => handleMedia(context);
