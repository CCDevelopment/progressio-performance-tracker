// Next.js (App Router or Pages Router) component. Server-renders the tracker
// tags into the page; put it once in the root layout.
//
//   import { PerformanceTracker } from '@progressio/performance-tracker/next';
//   <body>{children}<PerformanceTracker measurementId="G-XXXX" /></body>

import { createElement, Fragment } from 'react';
import { trackerScripts } from './head.js';

/** @param {import('../index').TrackerOptions} props */
export function PerformanceTracker(props) {
  return createElement(
    Fragment,
    null,
    ...trackerScripts(props).map((s, i) =>
      'src' in s
        ? createElement('script', { key: i, async: true, src: s.src })
        : createElement('script', { key: i, dangerouslySetInnerHTML: { __html: s.code } }),
    ),
  );
}
