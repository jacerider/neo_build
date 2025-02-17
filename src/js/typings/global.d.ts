/**
 * @file
 * This file is only used to provide typings and interfaces and is not output
 * as javascript.
 */

declare var displace:any;

declare function once(id: string, selector: string, context?: HTMLElement): Array<HTMLElement>;

declare function debounce(func: Function, wait?: number, immediate?: boolean): Function;

interface JQuery {
  findOnce:any;
  overlaps:any;
  drupalSetSummary:any;
}

interface Window {
  tabbable:any;
}
