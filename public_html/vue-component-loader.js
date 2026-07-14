'use strict';

// Component template loader kept with QuickStatements so application startup
// does not depend on the shared Toolforge asset host being available.
let vue_components = {
	toolname: window.location.pathname.replace(/(\/|\.php|\.html{0,1})+$/, '').replace(/^.*\//, ''),
	components: {},
	template_container_base_id: 'vue_component_templates',
	components_base_url: 'https://tools-static.wmflabs.org/magnustools/resources/vue/',
	serial_loading: false,
	fetch_timeout_ms: 0,
	fetch_retries: 0,
	loadComponents: function (components) {
		if (this.serial_loading) {
			return components.reduce((chain, component) => chain
				.then(() => this.fetchComponent(component))
				.then(html => this.injectComponent(component, html)), Promise.resolve());
		}
		return Promise.all(components.map(component => this.fetchComponent(component)))
			.then(fetched => fetched.map((html, i) => this.injectComponent(components[i], html)));
	},
	getComponentID: function (component) {
		if (typeof this.components[component] != 'undefined') return this.components[component];
		this.components[component] = this.template_container_base_id + '-' + Object.keys(this.components).length;
		return this.components[component];
	},
	getComponentURL: function (component) {
		return /^(http:|https:|\/|\.)/.test(component) || /\.html$/.test(component)
			? component
			: this.components_base_url + component + '.html';
	},
	loadComponent: function (component) {
		return this.fetchComponent(component).then(html => this.injectComponent(component, html));
	},
	fetchComponent: function (component) {
		let id = this.getComponentID(component);
		if ($('#' + id).length > 0) return Promise.resolve();
		let component_url = this.getComponentURL(component);
		return this.fetchComponentURL(component_url, this.fetch_retries);
	},
	fetchComponentURL: function (component_url, retries_left) {
		let controller = this.fetch_timeout_ms > 0 ? new AbortController() : null;
		let timeout = controller ? setTimeout(() => controller.abort(), this.fetch_timeout_ms) : null;
		return fetch(component_url, {
			cache: 'no-store',
			signal: controller ? controller.signal : undefined
		}).then(response => {
			if (!response.ok) throw new Error('Could not load component: ' + component_url);
			return response.text();
		}).finally(() => {
			if (timeout) clearTimeout(timeout);
		}).catch(error => {
			if (retries_left > 0) {
				console.warn('Retrying component after a loading failure:', component_url, error);
				return this.fetchComponentURL(component_url, retries_left - 1);
			}
			throw error;
		});
	},
	injectComponent: function (component, html) {
		if (!html) return;
		let id = this.getComponentID(component);
		$('body').append($('<div>').attr({id: id}).css({display: 'none'}).html(html));
	}
};
