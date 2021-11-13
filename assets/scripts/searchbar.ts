import { createApp } from 'vue';

const containerSearch = document.querySelector('#search');

containerSearch.addEventListener('click', () => {
     const inputSearch:HTMLElement = containerSearch.querySelector('input[type="text"]');
     inputSearch.focus();
});

createApp({
     compilerOptions: {
          delimiters: ["${", "}$"]
     },
     data() {
          return {
               timeout: null,
               isLoading: false,
               questions: null,
               fetchResult: async () => {
                    const value = this.$refs.input.value;
                    if (value?.length) {
                         try {
                              const response = await fetch(`/question/search/${ value }`);
                              if (response.ok) {
                                   const body = await response.json();
                                   this.questions = JSON.parse(body);
                                   console.log(this.questions);
                              } else {
                                   this.questions = null;
                              }
                              this.isLoading = false;
                         } catch(e) {
                              this.isLoading = false;
                              this.questions = null;
                         }
                    } else {
                         this.questions = null;
                         this.isLoading = false;
                    }
               }
          }
     },
     methods: {
          updateInput(event: KeyboardEvent) {
               clearTimeout(this.timeout);
               this.isLoading = true;
               this.timeout = setTimeout(this.fetchResult, 1000);
          },
          blurInput() {
               this.questions = null;
               this.isLoading = false;
          },
          focusInput() {
               if (this.$refs.input.value?.length) {
                    clearTimeout(this.timeout);
                    this.isLoading = true;
                    this.timeout = setTimeout(this.fetchResult, 1000);
               }
          }
     }
}).mount('#search');