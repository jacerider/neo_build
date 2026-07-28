/**
 * @file
 * This file is only used to provide typings and interfaces and is not output
 * as javascript.
 */

declare var displace:any;

declare function once(id: string, selector: string, context?: HTMLElement): Array<HTMLElement>;

/**
 * The rest of Drupal's core/once API, merged onto the call signature above.
 *
 * `remove()` is the counterpart a behaviour's detach() needs: without clearing
 * the stamp, a context that is detached and re-attached can never be processed
 * a second time.
 */
declare namespace once {
  function remove(id: string, selector: string, context?: HTMLElement): Array<HTMLElement>;
  function filter(id: string, selector: string, context?: HTMLElement): Array<HTMLElement>;
  function find(id?: string, context?: HTMLElement): Array<HTMLElement>;
}

declare function debounce(func: Function, wait?: number, immediate?: boolean): Function;

interface JQueryAutocompleteInstance {
  option(optionName: string, value: any): void;
  widget(): JQuery;
}

interface JQueryUIPosition {
  my: string;
  at: string;
  using?: (position: { top: number; left: number }, feedback: any) => void;
}

interface JQuery {
  findOnce:any;
  overlaps:any;
  drupalSetSummary:any;
  autocomplete(methodName: string): JQueryAutocompleteInstance | undefined;
  autocomplete(methodName: string, option: string, value: any): void;
  autocomplete(methodName: 'widget'): JQuery | undefined;
}

interface Window {
  tabbable:any;
}
