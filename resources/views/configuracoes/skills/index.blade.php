@extends('layouts.app')

@section('title', 'Central de Skills — Lead Certo')

@section('content')
<div class="max-w-6xl mx-auto" x-data="skillsData()">



    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Central de Skills</h1>
            <p class="text-gray-500 text-sm mt-1">Explore, clone e crie agentes de IA com instruções e posturas personalizadas.</p>
        </div>
        <div>
            <button @click="openCreateModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md font-medium text-sm flex items-center gap-2">
                <i class="fas fa-plus"></i> Nova Skill Autoral
            </button>
        </div>
    </div>

    @if(session('success'))
        <div class="bg-green-50 text-green-700 p-4 rounded-md mb-6 border border-green-200">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="bg-red-50 text-red-700 p-4 rounded-md mb-6 border border-red-200">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach($skills as $skill)
            <div class="bg-white border border-gray-200 rounded-lg shadow-sm hover:shadow-md transition flex flex-col">
                <div class="p-5 flex-1">
                    <div class="flex justify-between items-start mb-3">
                        <h3 class="font-bold text-lg text-gray-800 leading-tight">{{ $skill->titulo }}</h3>
                        @if($skill->origem === 'lead_certo')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Lead Certo</span>
                        @elseif($skill->origem === 'comprada')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">Comprada</span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">Autoral</span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 font-mono mb-3">{{ $skill->nome }}</p>
                    <p class="text-sm text-gray-600">{{ $skill->descricao_curta }}</p>
                </div>
                <div class="bg-gray-50 px-5 py-3 border-t border-gray-100 flex justify-between items-center rounded-b-lg">
                    <button @click="viewSkill({{ $skill }})" class="text-sm font-medium text-blue-600 hover:text-blue-800">
                        Ver Detalhes
                    </button>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Modal View/Edit --}}
    <div x-show="isModalOpen" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div x-show="isModalOpen" x-transition.opacity class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="closeModal()"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div x-show="isModalOpen" x-transition.scale.origin.bottom class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
                
                {{-- Form Mode --}}
                <form x-show="mode !== 'view'" :action="formAction" method="POST" class="w-full">
                    @csrf
                    <input type="hidden" name="_method" :value="formMethod">
                    
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <h3 class="text-lg leading-6 font-medium text-gray-900" x-text="mode === 'create' ? 'Nova Skill Autoral' : 'Editar Skill Autoral'"></h3>
                        
                        <div class="mt-4 grid grid-cols-1 gap-y-4 gap-x-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">Identificador (nome único, ex: meu-agente-vendas)</label>
                                <input type="text" name="nome" x-model="formData.nome" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-green-500 focus:border-green-500 sm:text-sm">
                            </div>
                            
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">Título de Exibição</label>
                                <input type="text" name="titulo" x-model="formData.titulo" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-green-500 focus:border-green-500 sm:text-sm">
                            </div>

                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">Descrição Curta (no card)</label>
                                <input type="text" name="descricao_curta" x-model="formData.descricao_curta" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-green-500 focus:border-green-500 sm:text-sm">
                            </div>

                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">Descrição Completa</label>
                                <textarea name="descricao_completa" x-model="formData.descricao_completa" rows="3" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-green-500 focus:border-green-500 sm:text-sm"></textarea>
                            </div>

                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">Instruções Base do Agente (SKILL.md)</label>
                                <textarea name="instrucoes_base" x-model="formData.instrucoes_base" rows="8" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-green-500 focus:border-green-500 sm:text-sm font-mono text-xs" placeholder="Cole aqui as regras de comportamento, tom de voz, gatilhos..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-green-600 text-base font-medium text-white hover:bg-green-700 focus:outline-none sm:ml-3 sm:w-auto sm:text-sm">
                            Salvar
                        </button>
                        <button type="button" @click="closeModal()" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                            Cancelar
                        </button>
                    </div>
                </form>

                {{-- View Mode --}}
                <div x-show="mode === 'view'" class="w-full text-left">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="flex justify-between items-start mb-4">
                            <h3 class="text-xl leading-6 font-bold text-gray-900" x-text="currentSkill.titulo"></h3>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" 
                                  :class="{
                                      'bg-green-100 text-green-800': currentSkill.origem === 'lead_certo',
                                      'bg-purple-100 text-purple-800': currentSkill.origem === 'comprada',
                                      'bg-blue-100 text-blue-800': currentSkill.origem === 'autoral'
                                  }" x-text="currentSkill.origem"></span>
                        </div>
                        <p class="text-sm text-gray-500 font-mono mb-4" x-text="currentSkill.nome"></p>
                        
                        <div class="prose text-sm text-gray-700 max-w-none mb-6">
                            <p x-text="currentSkill.descricao_completa || currentSkill.descricao_curta"></p>
                        </div>

                        <div x-show="currentSkill.instrucoes_base">
                            <h4 class="text-sm font-bold text-gray-900 uppercase tracking-wider mb-2">Instruções Base</h4>
                            <div class="bg-gray-900 rounded-md p-4 overflow-y-auto max-h-64">
                                <pre class="text-xs text-gray-300 font-mono whitespace-pre-wrap" x-text="currentSkill.instrucoes_base"></pre>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse justify-between items-center">
                        <div class="flex gap-2 flex-row-reverse">
                            <button type="button" @click="closeModal()" class="w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none sm:w-auto sm:text-sm">
                                Fechar
                            </button>
                            
                            <template x-if="currentSkill.origem !== 'autoral'">
                                <form :action="'{{ url('admin/skills') }}/' + currentSkill.id + '/duplicate'" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none sm:w-auto sm:text-sm">
                                        <i class="fas fa-copy mr-2"></i> Usar como Base
                                    </button>
                                </form>
                            </template>
                            
                            <template x-if="currentSkill.origem === 'autoral'">
                                <button type="button" @click="editSkill()" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none sm:w-auto sm:text-sm">
                                    <i class="fas fa-edit mr-2"></i> Editar
                                </button>
                            </template>
                        </div>
                        
                        <template x-if="currentSkill.origem === 'autoral'">
                            <form :action="'{{ url('admin/skills') }}/' + currentSkill.id" method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir esta skill?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:text-red-800 text-sm font-medium px-4 py-2">
                                    Excluir Skill
                                </button>
                            </form>
                        </template>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('skillsData', () => ({
            isModalOpen: false,
            mode: 'view', // view, create, edit
            currentSkill: {},
            formData: { nome: '', titulo: '', descricao_curta: '', descricao_completa: '', instrucoes_base: '' },
            formAction: '',
            formMethod: 'POST',
            
            viewSkill(skill) {
                this.currentSkill = skill;
                this.mode = 'view';
                this.isModalOpen = true;
            },
            
            openCreateModal() {
                this.formData = { nome: '', titulo: '', descricao_curta: '', descricao_completa: '', instrucoes_base: '' };
                this.formAction = '{{ route("admin.skills.store") }}';
                this.formMethod = 'POST';
                this.mode = 'create';
                this.isModalOpen = true;
            },
            
            editSkill() {
                this.formData = { 
                    nome: this.currentSkill.nome, 
                    titulo: this.currentSkill.titulo, 
                    descricao_curta: this.currentSkill.descricao_curta, 
                    descricao_completa: this.currentSkill.descricao_completa, 
                    instrucoes_base: this.currentSkill.instrucoes_base 
                };
                this.formAction = '{{ url("admin/skills") }}/' + this.currentSkill.id;
                this.formMethod = 'PUT';
                this.mode = 'edit';
            },
            
            closeModal() {
                this.isModalOpen = false;
            }
        }));
    });
</script>
@endsection
