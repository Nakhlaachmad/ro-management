import {qualityMetrics} from './document-quality.js';
self.onmessage=({data})=>self.postMessage(qualityMetrics(new Uint8ClampedArray(data.buffer),data.width,data.height));