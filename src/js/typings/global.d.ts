/**
 * @file
 * This file is only used to provide typings and interfaces and is not output
 * as javascript.
 */

declare var displace:any;

declare function once(id: string, selector: string, context?: HTMLElement): Array<HTMLElement>;

interface JQuery {
  findOnce:any;
  overlaps:any;
}

interface Window {
  tabbable:any;
}
